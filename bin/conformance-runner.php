<?php

declare(strict_types=1);

/**
 * The PHP conformance runner.
 *
 * Reads one scenario as JSON on stdin, drives this SDK against the mock server
 * the driver started, and prints one JSON report on stdout. See
 * contract/harness/runners/README.md for the contract.
 *
 * Exits non-zero only if the runner itself broke. An SDK misbehaving is a
 * report for the driver to judge, not a crash here.
 */

require __DIR__ . '/../vendor/autoload.php';

use MarginFuse\Acknowledgment;
use MarginFuse\Client;
use MarginFuse\Decision;
use MarginFuse\Outcome;
use MarginFuse\ProviderCall;
use MarginFuse\Usage;

/** @var array<string, mixed> $scenario */
$scenario = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);

/** @var list<array<string, string>> $providerCalls */
$providerCalls = [];
/** @var list<string> $onErrorContexts */
$onErrorContexts = [];

/** @var array<string, mixed> $options */
$options = is_array($scenario['options'] ?? null) ? $scenario['options'] : [];
$timeout = isset($options['timeoutMs']) && is_numeric($options['timeoutMs'])
    ? ((float) $options['timeoutMs']) / 1000.0
    : 1.5;

$mf = new Client(
    apiKey: (string) getenv('MARGINFUSE_API_KEY'),
    baseUrl: (string) getenv('MARGINFUSE_BASE_URL'),
    timeout: $timeout,
    onError: static function (Throwable $_e, string $context) use (&$onErrorContexts): void {
        $onErrorContexts[] = $context;
    },
);

/** @var array<string, mixed> $params */
$params = is_array($scenario['params'] ?? null) ? $scenario['params'] : [];

/** @param mixed $raw */
$usageFrom = static function ($raw): Usage {
    if (!is_array($raw)) {
        return new Usage();
    }
    $int = static fn (string $k) => isset($raw[$k]) && is_numeric($raw[$k]) ? (int) $raw[$k] : null;

    return new Usage(
        inputTokens: $int('inputTokens'),
        outputTokens: $int('outputTokens'),
        cachedInputTokens: $int('cachedInputTokens'),
        cacheCreationTokens: $int('cacheCreationTokens'),
        images: $int('images'),
        audioSeconds: isset($raw['audioSeconds']) && is_numeric($raw['audioSeconds'])
            ? (float) $raw['audioSeconds']
            : null,
    );
};

$str = static fn (string $k): ?string => isset($params[$k]) && is_string($params[$k]) && $params[$k] !== ''
    ? $params[$k]
    : null;

$decisionJson = static fn (Decision $d): array => [
    'id' => $d->id,
    'action' => $d->action->value,
    'model' => $d->model,
    'provider' => $d->provider,
    'topupContext' => $d->topupContext,
    'degraded' => $d->degraded,
    'degradedReason' => $d->degradedReason,
];

/** @var array<string, mixed> $report */
$report = ['outcome' => 'returned'];

$action = is_string($scenario['action'] ?? null) ? $scenario['action'] : '';

try {
    switch ($action) {
        case 'decide':
            $report['result'] = $decisionJson($mf->decide(
                customerId: (string) $str('customerId'),
                provider: (string) $str('provider'),
                model: (string) $str('model'),
                feature: $str('feature'),
                expectedUsage: isset($params['expectedUsage']) ? $usageFrom($params['expectedUsage']) : null,
                plan: $str('plan'),
            ));
            break;

        case 'track':
            $mf->track(
                customerId: (string) $str('customerId'),
                provider: (string) $str('provider'),
                model: (string) $str('model'),
                usage: isset($params['usage']) ? $usageFrom($params['usage']) : null,
                feature: $str('feature'),
                requestedModel: $str('requestedModel'),
                costUsd: $str('costUsd'),
                eventId: $str('eventId'),
                outcome: Outcome::tryFrom((string) ($str('outcome') ?? 'success')) ?? Outcome::Success,
                decisionId: $str('decisionId'),
                plan: $str('plan'),
            );
            break;

        case 'acknowledge':
            $mf->acknowledge(
                (string) $str('decisionId'),
                Acknowledgment::from((string) $str('acknowledgment')),
            );
            break;

        case 'identify':
            // The one call that reports failure instead of failing open: a
            // wrong plan is a wrong margin, so the application must see it.
            /** @var array<string, string>|null $metadata */
            $metadata = is_array($params['metadata'] ?? null) ? $params['metadata'] : null;
            $periodStart = $str('periodStart');
            $identity = $mf->identify(
                customerId: (string) $str('customerId'),
                plan: $str('plan'),
                clearPlan: ($params['clearPlan'] ?? false) === true,
                periodStart: $periodStart !== null ? new DateTimeImmutable($periodStart) : null,
                name: $str('name'),
                email: $str('email'),
                metadata: $metadata,
            );
            $report['result'] = [
                'ok' => $identity->ok,
                'customerId' => $identity->customerId,
                'plan' => $identity->plan,
                'periodStart' => $identity->periodStart,
                'periodEnd' => $identity->periodEnd,
                'error' => $identity->error,
            ];
            break;

        case 'guard':
            /** @var array<string, mixed> $spec */
            $spec = is_array($scenario['provider'] ?? null) ? $scenario['provider'] : [];
            $outcome = $mf->guard(
                run: static function (Decision $decision) use ($spec, $usageFrom, &$providerCalls): ProviderCall {
                    $providerCalls[] = ['model' => $decision->model, 'provider' => $decision->provider];
                    if (($spec['throws'] ?? false) === true) {
                        throw new RuntimeException('provider exploded');
                    }

                    return new ProviderCall(usage: $usageFrom($spec['usage'] ?? null), result: 'ok');
                },
                customerId: (string) $str('customerId'),
                provider: (string) $str('provider'),
                model: (string) $str('model'),
                feature: $str('feature'),
                plan: $str('plan'),
            );
            // Only the discriminant and the decision travel; the application's
            // own result means nothing to another language.
            $report['result'] = [
                'kind' => $outcome->kind->value,
                'decision' => $decisionJson($outcome->decision),
            ];
            break;

        default:
            fwrite(STDERR, "unknown action {$action}\n");
            exit(1);
    }
} catch (Throwable $e) {
    $report['outcome'] = 'threw';
    $report['threw'] = $e->getMessage();
}

// Always flush, including after a throw: the driver asserts on what the SDK
// sent, and guard records the attempt before it rethrows.
$mf->flush();

$report['providerCalls'] = $providerCalls;
$report['onErrorContexts'] = $onErrorContexts;
echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
