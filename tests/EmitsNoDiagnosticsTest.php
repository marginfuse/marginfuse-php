<?php

declare(strict_types=1);

namespace MarginFuse\Tests;

use MarginFuse\Acknowledgment;
use MarginFuse\Client;
use MarginFuse\DecisionAction;
use MarginFuse\Usage;
use PHPUnit\Framework\TestCase;

/**
 * An SDK sits inside somebody else's request path, so it must be silent: no
 * deprecation, warning or notice may escape into the host application.
 *
 * This is not housekeeping. v0.1.0 called curl_close(), a no-op since PHP 8.0
 * and deprecated in 8.5. Applications that promote deprecations to exceptions,
 * which Symfony's debug handler does, turned that notice into a transport
 * failure inside post(), and every call fell into the fail-open path: a real
 * block verdict came back as allow, blamed on a server that had answered
 * perfectly. The guardrails were off and the reason given was false.
 *
 * The unit tests never made an HTTP request, so nothing here saw it, and CI
 * stopped at 8.4, so nothing there ran the version that warns.
 *
 * No server is needed. An unreachable port still executes the whole curl call
 * surface, which is where such a call would sit.
 */
final class EmitsNoDiagnosticsTest extends TestCase
{
    /** Port 9 is discard. Nothing accepts, so every request fails transport. */
    private const UNREACHABLE = 'http://127.0.0.1:9';

    public function testAFullRequestCycleEmitsNoDiagnostic(): void
    {
        /** @var list<string> $seen */
        $seen = [];

        set_error_handler(
            static function (int $severity, string $message) use (&$seen): bool {
                if (($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_WARNING | E_NOTICE)) !== 0) {
                    $seen[] = $message;
                }

                return true;
            },
        );

        try {
            $mf = new Client(apiKey: 'mf_test', baseUrl: self::UNREACHABLE, timeout: 0.5);
            $mf->decide(customerId: 'cus_test', provider: 'openai', model: 'gpt-4.1');
            $mf->track(
                customerId: 'cus_test',
                provider: 'openai',
                model: 'gpt-4.1',
                usage: new Usage(inputTokens: 1204, outputTokens: 388),
            );
            $mf->acknowledge('dec_test', Acknowledgment::ProceededAsRequested);
            $mf->flush();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $seen, 'the SDK emitted diagnostics into the application');
    }

    public function testAStrictDeprecationHandlerCannotBecomeATransportFailure(): void
    {
        /** @var list<string> $reported */
        $reported = [];

        set_error_handler(static function (int $severity, string $message): bool {
            if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        });

        try {
            $mf = new Client(
                apiKey: 'mf_test',
                baseUrl: self::UNREACHABLE,
                timeout: 0.5,
                onError: static function (\Throwable $e) use (&$reported): void {
                    $reported[] = $e->getMessage();
                },
            );
            $decision = $mf->decide(customerId: 'cus_test', provider: 'openai', model: 'gpt-4.1');
        } finally {
            restore_error_handler();
        }

        // Failing open here is correct: the host really is unreachable. What
        // must not happen is the SDK's own deprecation being the cause.
        self::assertSame(DecisionAction::Allow, $decision->action);
        self::assertTrue($decision->degraded);

        foreach ($reported as $message) {
            self::assertStringNotContainsStringIgnoringCase(
                'deprecated',
                $message,
                'a deprecation from inside the SDK was reported as a transport failure',
            );
        }
    }
}
