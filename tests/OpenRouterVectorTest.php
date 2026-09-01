<?php

declare(strict_types=1);

namespace MarginFuse\Tests;

use MarginFuse\Contract;
use MarginFuse\OpenRouter;
use MarginFuse\OpenRouterMapping;
use MarginFuse\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Driven entirely by contract/conformance/gateway-vectors.json, which every SDK
 * in every language reads.
 *
 * Assertions written here instead would be a second copy of the truth, and this
 * SDK would slowly stop agreeing with the others. To add a case, edit the
 * vector file, not this test.
 */
final class OpenRouterVectorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function vectors(): array
    {
        $doc = json_decode(
            (string) file_get_contents(__DIR__ . '/../contract/conformance/gateway-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($doc);
        self::assertIsArray($doc['adapters']);
        self::assertIsArray($doc['adapters']['fromOpenRouter']);

        /** @var list<array<string, mixed>> $cases */
        $cases = $doc['adapters']['fromOpenRouter']['cases'];
        self::assertNotEmpty($cases);

        $out = [];
        foreach ($cases as $case) {
            /** @var string $name */
            $name = $case['name'];
            $out[$name] = [$case];
        }

        return $out;
    }

    /** @param array<string, mixed> $case */
    private static function mapCase(array $case): OpenRouterMapping
    {
        if (($case['omitInput'] ?? false) === true) {
            return OpenRouter::from();
        }

        /** @var null|array<string, mixed> $input */
        $input = $case['input'];

        return OpenRouter::from($input);
    }

    /** @return array<string, int|float> only the fields the adapter actually set */
    private static function produced(Usage $usage): array
    {
        return $usage->toWire();
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('vectors')]
    public function testGatewayVector(array $case): void
    {
        $mapped = self::mapCase($case);

        /** @var array<string, mixed> $expected */
        $expected = $case['expected'];
        /** @var array<string, int|float> $wantUsage */
        $wantUsage = $expected['usage'];

        self::assertEqualsCanonicalizing(
            array_keys($wantUsage),
            array_keys(self::produced($mapped->usage)),
            'usage fields',
        );
        foreach ($wantUsage as $field => $want) {
            self::assertEquals($want, self::produced($mapped->usage)[$field], "usage.{$field}");
        }

        if (array_key_exists('costUsd', $expected)) {
            self::assertSame($expected['costUsd'], $mapped->costUsd);
        } else {
            // Absent must mean absent, not present-and-zero: omitting the cost
            // lets MarginFuse price the call, where "0" would claim it was free.
            self::assertNull($mapped->costUsd, 'costUsd should have been omitted');
        }
    }

    public function testNeverProducesACostTheApiWouldReject(): void
    {
        // The decimal-string pattern from the API's own schema. Exponent
        // notation is the failure this guards, and it is silent elsewhere.
        foreach (self::vectors() as $name => [$case]) {
            $cost = self::mapCase($case)->costUsd;
            if ($cost !== null) {
                self::assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $cost, $name);
            }
        }
    }

    public function testContractVersionMatchesThePinnedContract(): void
    {
        /** @var array{version: int} $pinned */
        $pinned = json_decode(
            (string) file_get_contents(__DIR__ . '/../contract/conformance/behavior-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($pinned['version'], Contract::VERSION);
    }
}
