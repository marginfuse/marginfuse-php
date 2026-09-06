<?php

declare(strict_types=1);

namespace MarginFuse\Tests;

use MarginFuse\Client;
use MarginFuse\Decision;
use MarginFuse\DecisionAction;
use MarginFuse\GuardKind;
use MarginFuse\ProviderCall;
use MarginFuse\Tests\Support\RecordingServer;
use PHPUnit\Framework\TestCase;

final class DecisionValidationTest extends TestCase
{
    public function testMalformedDecisionsFailOpen(): void
    {
        foreach ([null, false, 42, 'allow', [], ['action' => 'unknown'], ['action' => 'allow', 'model' => 42], ['action' => 'allow', 'provider' => false], ['action' => 'allow', 'model' => ' '], ['action' => 'allow', 'provider' => ' '], ['action' => 'downgrade'], ['action' => 'downgrade', 'model' => null], ['action' => 'block', 'degraded' => 'false']] as $payload) {
            $server = RecordingServer::start($payload);
            $contexts = [];
            try {
                $client = new Client(apiKey: 'test', baseUrl: $server->baseUrl, onError: static function (\Throwable $error, string $context) use (&$contexts): void {
                    $contexts[] = $context;
                });
                $decision = $client->decide('customer', 'openai', 'original');
                self::assertSame(DecisionAction::Allow, $decision->action);
                self::assertSame('original', $decision->model);
                self::assertSame('openai', $decision->provider);
                self::assertTrue($decision->degraded, json_encode($payload, JSON_THROW_ON_ERROR));
                self::assertSame(['decide'], $contexts);
            } finally {
                $server->stop();
            }
        }
    }

    public function testMalformedIdentityNeverThrows(): void
    {
        foreach ([null, false, 42, 'customer'] as $payload) {
            $server = RecordingServer::start($payload);
            $contexts = [];
            try {
                $client = new Client(apiKey: 'test', baseUrl: $server->baseUrl, onError: static function (\Throwable $error, string $context) use (&$contexts): void {
                    $contexts[] = $context;
                });
                $identity = $client->identify('customer');
                self::assertFalse($identity->ok);
                self::assertNotNull($identity->error);
                self::assertSame(['identify'], $contexts);
            } finally {
                $server->stop();
            }
        }
    }

    public function testBlockWithoutIdNeverCallsProvider(): void
    {
        $server = RecordingServer::start(['action' => 'block']);
        try {
            $client = new Client(apiKey: 'test', baseUrl: $server->baseUrl);
            $outcome = $client->guard(run: static function (Decision $decision): ProviderCall {
                self::fail('blocked request reached provider');
            }, customerId: 'customer', provider: 'openai', model: 'original');
            self::assertSame(GuardKind::Blocked, $outcome->kind);
            self::assertFalse($outcome->decision->degraded);
        } finally {
            $server->stop();
        }
    }
}
