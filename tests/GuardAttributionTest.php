<?php

declare(strict_types=1);

namespace MarginFuse\Tests;

use MarginFuse\Client;
use MarginFuse\Decision;
use MarginFuse\GuardKind;
use MarginFuse\ProviderCall;
use MarginFuse\Tests\Support\RecordingServer;
use MarginFuse\Usage;
use PHPUnit\Framework\TestCase;

/**
 * What guard() says a downgraded call was.
 *
 * A downgrade can cross vendors: the server may answer an OpenAI request with
 * an Anthropic model. Report the caller's provider anyway and the call is
 * priced from the wrong vendor's catalogue, attributed to a vendor that never
 * ran it, and the saving the downgrade exists to prove is measured against the
 * wrong basis. Every one of those is silent, so the assertions are on the wire.
 */
final class GuardAttributionTest extends TestCase
{
    public function testACrossProviderDowngradeIsBilledToTheVendorThatRan(): void
    {
        $server = RecordingServer::start([
            'id' => 'dec_downgraded',
            'action' => 'downgrade',
            'model' => 'claude-haiku-4.5',
            'provider' => 'anthropic',
        ]);

        try {
            $mf = new Client(apiKey: 'mf_test', baseUrl: $server->baseUrl, timeout: 5.0);

            $ranOn = null;
            $outcome = $mf->guard(
                run: static function (Decision $decision) use (&$ranOn): ProviderCall {
                    $ranOn = $decision->provider . '/' . $decision->model;

                    return new ProviderCall(
                        usage: new Usage(inputTokens: 1204, outputTokens: 388),
                        result: 'ok',
                    );
                },
                customerId: 'cus_test',
                provider: 'openai',
                model: 'gpt-4.1',
            );
            $mf->flush();

            $events = $server->events();
            $acknowledgments = $server->acknowledgments('dec_downgraded');
        } finally {
            $server->stop();
        }

        self::assertSame(GuardKind::Completed, $outcome->kind);
        self::assertSame('anthropic/claude-haiku-4.5', $ranOn, 'the callback was handed the wrong call');

        self::assertCount(1, $events);
        self::assertSame('anthropic', $events[0]['provider'], 'billed to the vendor that was asked, not the one that ran');
        self::assertSame('claude-haiku-4.5', $events[0]['model']);
        self::assertSame('gpt-4.1', $events[0]['requestedModel']);
        self::assertSame(['used_downgrade_model'], $acknowledgments);
    }

    public function testADowngradeThatFailsIsAcknowledgedAsADowngrade(): void
    {
        $server = RecordingServer::start([
            'id' => 'dec_downgraded',
            'action' => 'downgrade',
            'model' => 'claude-haiku-4.5',
            'provider' => 'anthropic',
        ]);

        $thrownClass = null;
        $thrownMessage = null;

        try {
            $mf = new Client(apiKey: 'mf_test', baseUrl: $server->baseUrl, timeout: 5.0);

            try {
                $mf->guard(
                    run: static function (Decision $decision): ProviderCall {
                        throw new \RuntimeException('anthropic is down');
                    },
                    customerId: 'cus_test',
                    provider: 'openai',
                    model: 'gpt-4.1',
                );
            } catch (\Throwable $e) {
                $thrownClass = $e::class;
                $thrownMessage = $e->getMessage();
            }
            $mf->flush();

            $events = $server->events();
            $acknowledgments = $server->acknowledgments('dec_downgraded');
        } finally {
            $server->stop();
        }

        // The application's own error, unchanged: guard records the attempt
        // and then gets out of the way.
        self::assertSame(\RuntimeException::class, $thrownClass);
        self::assertSame('anthropic is down', $thrownMessage);

        // The cheaper model ran and cost whatever it cost before it failed.
        // Acknowledging "proceeded as requested" here would tell MarginFuse
        // the downgrade was never applied.
        self::assertSame(['used_downgrade_model'], $acknowledgments);

        self::assertCount(1, $events);
        self::assertSame('anthropic', $events[0]['provider']);
        self::assertSame('claude-haiku-4.5', $events[0]['model']);
        self::assertSame('gpt-4.1', $events[0]['requestedModel']);
        self::assertSame('provider_error', $events[0]['outcome']);
    }

    /** The ordinary verdict, where the server names no provider of its own. */
    public function testAnAllowIsStillBilledToTheVendorTheCallerAsked(): void
    {
        $server = RecordingServer::start([
            'id' => 'dec_allowed',
            'action' => 'allow',
        ]);

        try {
            $mf = new Client(apiKey: 'mf_test', baseUrl: $server->baseUrl, timeout: 5.0);

            $mf->guard(
                run: static fn (Decision $decision): ProviderCall => new ProviderCall(
                    usage: new Usage(inputTokens: 1204, outputTokens: 388),
                    result: 'ok',
                ),
                customerId: 'cus_test',
                provider: 'openai',
                model: 'gpt-4.1',
            );
            $mf->flush();

            $events = $server->events();
            $acknowledgments = $server->acknowledgments('dec_allowed');
        } finally {
            $server->stop();
        }

        self::assertCount(1, $events);
        self::assertSame('openai', $events[0]['provider']);
        self::assertSame('gpt-4.1', $events[0]['model']);
        self::assertSame(['proceeded_as_requested'], $acknowledgments);
    }
}
