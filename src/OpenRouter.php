<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * What {@see OpenRouter::from()} produced.
 */
final readonly class OpenRouterMapping
{
    public function __construct(
        public Usage $usage,
        /**
         * The gateway's own cost as a decimal string, or null when the response
         * carried none. Null lets the event fall through to MarginFuse's own
         * pricing instead of claiming a $0 charge.
         */
        public ?string $costUsd = null,
    ) {
    }
}

/**
 * OpenRouter helper.
 *
 * OpenRouter returns a `usage` object carrying the provider-final `cost`.
 * Forwarding it is what makes an OpenRouter integration exact rather than
 * estimated: MarginFuse cannot know what a gateway charged, because routing,
 * fees and BYOK terms are not visible in a usage event.
 *
 * Two details this helper exists to get right, both of which silently misstate
 * margin when hand-rolled:
 *
 * 1. `prompt_tokens` is the TOTAL input count. Cached reads and cache writes
 *    are already inside it, and MarginFuse prices those as three separate
 *    charges and adds them up, so passing the total through charges every
 *    cached token twice at the full uncached rate.
 * 2. `cost` is a float, and the default string conversion renders small ones in
 *    exponent notation (`1.2E-7`), which the API rejects as a decimal string.
 */
final class OpenRouter
{
    private function __construct()
    {
    }

    /**
     * Maps a decoded OpenRouter `usage` object.
     *
     * @param null|array<string, mixed> $usage
     */
    public static function from(?array $usage = null): OpenRouterMapping
    {
        $source = $usage ?? [];
        $details = $source['prompt_tokens_details'] ?? null;
        $details = is_array($details) ? $details : [];

        $cached = self::toInt($details['cached_tokens'] ?? null);
        $cacheWrites = self::toInt($details['cache_write_tokens'] ?? null);
        // What is left after the cached parts is what was billed at the full
        // input rate. Clamped at zero so a provider reporting these differently
        // degrades to "no fresh input" rather than a negative charge.
        $fresh = max(0, self::toInt($source['prompt_tokens'] ?? null) - $cached - $cacheWrites);
        $completion = self::toInt($source['completion_tokens'] ?? null);

        $mapped = new Usage(
            inputTokens: $fresh > 0 ? $fresh : null,
            outputTokens: $completion > 0 ? $completion : null,
            cachedInputTokens: $cached > 0 ? $cached : null,
            cacheCreationTokens: $cacheWrites > 0 ? $cacheWrites : null,
        );

        $cost = $source['cost'] ?? null;
        if (!is_int($cost) && !is_float($cost)) {
            return new OpenRouterMapping($mapped);
        }
        $cost = (float) $cost;
        if (is_nan($cost) || is_infinite($cost) || $cost < 0) {
            return new OpenRouterMapping($mapped);
        }

        return new OpenRouterMapping($mapped, self::creditsToUsd($cost));
    }

    private static function toInt(mixed $value): int
    {
        if (!is_int($value) && !is_float($value)) {
            return 0;
        }
        $number = (float) $value;
        if (is_nan($number) || is_infinite($number) || $number <= 0) {
            return 0;
        }

        return (int) round($number);
    }

    /**
     * OpenRouter credits (1 credit = 1 USD) as a decimal string the API takes.
     *
     * Formatted to ten decimals and then truncated to nine, rather than
     * rounded: money below a nano cannot be represented, so it rounds down
     * instead of inventing precision it does not have.
     */
    public static function creditsToUsd(float $cost): string
    {
        $text = substr(number_format($cost, 10, '.', ''), 0, -1);
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
