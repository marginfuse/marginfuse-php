<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * Server-side SDK for MarginFuse: profitability guardrails for AI SaaS.
 *
 * Reliability contract: this SDK never throws into application code and never
 * blocks a request on MarginFuse availability. {@see self::decide()} fails open
 * to `DecisionAction::Allow` on any timeout or error, and problems surface only
 * through the `onError` handler.
 *
 * Zero dependencies beyond ext-curl and ext-json. Server side only: it carries
 * a secret API key.
 *
 * PHP has no background threads, so {@see self::track()} buffers the event and
 * the buffer is sent by {@see self::flush()}, which also runs automatically at
 * shutdown. Under FPM, call `fastcgi_finish_request()` first if you want the
 * response on its way before that happens.
 */
final class Client
{
    private const DEFAULT_BASE_URL = 'https://api.marginfuse.com';
    private const DEFAULT_TIMEOUT = 1.5;
    private const TRACK_RETRIES = 3;
    private const USER_AGENT = 'marginfuse-php/' . Version::VALUE;

    private readonly string $baseUrl;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $acknowledgments = [];

    private bool $shutdownRegistered = false;

    /**
     * @param string        $apiKey  your project API key
     * @param string        $baseUrl point at your own deployment in development
     * @param float         $timeout seconds decide() waits before failing open
     * @param null|callable(\Throwable, string): void $onError receives failures the SDK
     *        swallowed. Without it they are silent by design: this SDK is in
     *        your request path and must not become your outage.
     */
    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly mixed $onError = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('MarginFuse: apiKey is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Asks whether the next call should run. Always returns a verdict.
     *
     * There is no exception and no null return on purpose. A failed decision is
     * not a condition to branch on: it is an allow with `degraded` set, because
     * MarginFuse being unreachable must never become your outage.
     */
    public function decide(
        string $customerId,
        string $provider,
        string $model,
        ?string $feature = null,
        ?Usage $expectedUsage = null,
    ): Decision {
        $failOpen = static fn (string $reason): Decision => new Decision(
            action: DecisionAction::Allow,
            model: $model,
            provider: $provider,
            degraded: true,
            degradedReason: $reason,
        );

        $body = array_filter([
            'customerId' => $customerId,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'expectedUsage' => $expectedUsage?->toWire() ?: null,
        ], static fn (mixed $v): bool => $v !== null);

        try {
            [$status, $raw] = $this->post('/v1/decisions', $body, $this->timeout);
        } catch (TimeoutException $e) {
            $this->report($e, 'decide');

            return $failOpen('timeout');
        } catch (\Throwable $e) {
            $this->report($e, 'decide');

            return $failOpen('unreachable');
        }

        if ($status < 200 || $status >= 300) {
            $this->report(new \RuntimeException("decide: HTTP {$status}"), 'decide');

            return $failOpen("server responded {$status}");
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->report($e, 'decide');

            return $failOpen('unreadable response');
        }

        return new Decision(
            action: DecisionAction::fromWire(self::str($parsed, 'action')),
            model: self::str($parsed, 'model') ?? $model,
            provider: self::str($parsed, 'provider') ?? $provider,
            id: self::str($parsed, 'id'),
            topupContext: self::str($parsed, 'topupContext'),
            degraded: (bool) ($parsed['degraded'] ?? false),
            degradedReason: self::str($parsed, 'degradedReason'),
        );
    }

    /**
     * Reports a call that already happened. Buffers it and returns immediately.
     *
     * The buffer is sent by {@see self::flush()}, which also runs at shutdown.
     * PHP has no background threads, so this is what "does not block your
     * request" means here.
     */
    public function track(
        string $customerId,
        string $provider,
        string $model,
        ?Usage $usage = null,
        ?string $feature = null,
        ?string $requestedModel = null,
        ?string $costUsd = null,
        ?string $eventId = null,
        ?\DateTimeInterface $occurredAt = null,
        Outcome $outcome = Outcome::Success,
        ?string $decisionId = null,
        ?string $retryOfEventId = null,
        ?string $correctsEventId = null,
    ): void {
        $when = $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->buffer[] = array_filter([
            'eventId' => $eventId ?? 'evt_' . self::uuid4(),
            'customerId' => $customerId,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'requestedModel' => $requestedModel,
            'usage' => ($usage ?? new Usage())->toWire(),
            'costUsd' => $costUsd,
            'occurredAt' => $when->format('Y-m-d\TH:i:s.u\Z'),
            'outcome' => $outcome->value,
            'decisionId' => $decisionId,
            'retryOfEventId' => $retryOfEventId,
            'correctsEventId' => $correctsEventId,
        ], static fn (mixed $v): bool => $v !== null);

        $this->registerShutdownFlush();
    }

    /** Tells MarginFuse what your application did with a decision. */
    public function acknowledge(string $decisionId, Acknowledgment $acknowledgment): void
    {
        $this->acknowledgments[] = [
            '/v1/decisions/' . rawurlencode($decisionId) . '/ack',
            ['acknowledgment' => $acknowledgment->value],
        ];
        $this->registerShutdownFlush();
    }

    /**
     * Runs the whole loop: ask, run, report, acknowledge.
     *
     * `$run` receives the decision and must return a {@see ProviderCall}. Use
     * `$decision->model`: a downgrade verdict changes it.
     *
     * It takes a callable rather than returning a decision for you to act on,
     * because enforcement must not depend on the caller remembering to check
     * anything. When the verdict is block, `$run` is never invoked.
     *
     * An exception from `$run` propagates unchanged: your error handling owns
     * provider failures. The attempt is recorded first, because the provider
     * may still have charged for it.
     *
     * @param callable(Decision): ProviderCall $run
     */
    public function guard(
        callable $run,
        string $customerId,
        string $provider,
        string $model,
        ?string $feature = null,
        ?Usage $expectedUsage = null,
    ): GuardOutcome {
        $decision = $this->decide($customerId, $provider, $model, $feature, $expectedUsage);

        // Enforcement depends on the ACTION alone. A missing id costs an
        // acknowledgment; it must never turn a block into a provider call.
        if ($decision->action === DecisionAction::Block) {
            if ($decision->id !== null) {
                $this->acknowledge($decision->id, Acknowledgment::BlockedBeforeProviderCall);
            }

            return new GuardOutcome(GuardKind::Blocked, $decision);
        }
        if ($decision->action === DecisionAction::TopupRequired) {
            if ($decision->id !== null) {
                $this->acknowledge($decision->id, Acknowledgment::PresentedTopup);
            }

            return new GuardOutcome(GuardKind::TopupRequired, $decision);
        }

        $modelUsed = $decision->action === DecisionAction::Downgrade ? $decision->model : $model;

        try {
            $call = $run($decision);
        } catch (\Throwable $e) {
            $this->track(
                customerId: $customerId,
                provider: $provider,
                model: $modelUsed,
                feature: $feature,
                requestedModel: $model,
                outcome: Outcome::ProviderError,
                decisionId: $decision->id,
            );
            if ($decision->id !== null) {
                $this->acknowledge($decision->id, Acknowledgment::ProceededAsRequested);
            }

            throw $e;
        }

        $this->track(
            customerId: $customerId,
            provider: $provider,
            model: $modelUsed,
            usage: $call->usage,
            feature: $feature,
            requestedModel: $model,
            costUsd: $call->costUsd,
            outcome: $call->outcome,
            decisionId: $decision->id,
        );
        if ($decision->id !== null) {
            $this->acknowledge(
                $decision->id,
                $decision->action === DecisionAction::Downgrade
                    ? Acknowledgment::UsedDowngradeModel
                    : Acknowledgment::ProceededAsRequested,
            );
        }

        return new GuardOutcome(GuardKind::Completed, $decision, $call->result);
    }

    /**
     * Sends everything buffered. Never throws.
     *
     * Runs automatically at shutdown, so calling it is only necessary when you
     * want the events gone sooner, or in a long-running worker that never
     * shuts down between jobs.
     */
    public function flush(): void
    {
        $events = $this->buffer;
        $this->buffer = [];
        foreach ($events as $event) {
            $this->sendEvent($event);
        }

        $acks = $this->acknowledgments;
        $this->acknowledgments = [];
        foreach ($acks as [$path, $body]) {
            try {
                [$status] = $this->post($path, $body, 5.0);
                if ($status < 200 || $status >= 300) {
                    $this->report(new \RuntimeException("ack: HTTP {$status}"), 'acknowledge');
                }
            } catch (\Throwable $e) {
                $this->report($e, 'acknowledge');
            }
        }
    }

    // ----------------------------------------------------------- internals

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    /** @param array<string, mixed> $event */
    private function sendEvent(array $event): void
    {
        $last = new \RuntimeException('track: not attempted');
        for ($attempt = 0; $attempt < self::TRACK_RETRIES; ++$attempt) {
            try {
                [$status, $raw] = $this->post('/v1/events', ['events' => [$event]], 5.0);
                if ($status >= 200 && $status < 300) {
                    return;
                }
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    // A malformed event is malformed on every attempt.
                    $this->report(
                        new \RuntimeException("track: HTTP {$status} " . substr($raw, 0, 200)),
                        'track',
                    );

                    return;
                }
                $last = new \RuntimeException("track: HTTP {$status}");
            } catch (\Throwable $e) {
                $last = $e;
            }
            usleep((int) (250_000 * (2 ** $attempt)));
        }
        $this->report($last, 'track');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: string}
     */
    private function post(string $path, array $body, float $timeout): array
    {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new \RuntimeException('marginfuse: could not initialise curl');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'authorization: Bearer ' . $this->apiKey,
                'content-type: application/json',
                'user-agent: ' . self::USER_AGENT,
            ],
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
        ]);

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(). Since PHP 8.0 the handle is an object freed by
        // refcount, so the call does nothing, and since 8.5 it raises a
        // deprecation. Under a handler that promotes deprecations to
        // exceptions that notice became a transport failure, so a real
        // block verdict came back as a fail-open allow.

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new TimeoutException($error);
        }
        // curl_exec is typed string|bool. With RETURNTRANSFER a success is a
        // string; anything else is a transport failure, including the `true`
        // the signature allows and this configuration never produces.
        if (!is_string($raw)) {
            throw new \RuntimeException("marginfuse: " . ($error !== '' ? $error : 'no response body'));
        }

        return [$status, $raw];
    }

    private function report(\Throwable $error, string $context): void
    {
        if (!is_callable($this->onError)) {
            return;
        }

        try {
            ($this->onError)($error, $context);
        } catch (\Throwable) {
            // a broken handler is not our failure mode
        }
    }

    /** @param array<string, mixed> $source */
    private static function str(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
