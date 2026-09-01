<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * What a provider call consumed.
 *
 * Every property is nullable and null means *not reported*, not "used none":
 * a null is left off the request entirely, because claiming a call used zero
 * input tokens is a different statement from not knowing what it used.
 */
final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $cachedInputTokens = null,
        public ?int $cacheCreationTokens = null,
        public ?int $images = null,
        public ?float $audioSeconds = null,
    ) {
    }

    /** @return array<string, int|float> the fields that were actually set */
    public function toWire(): array
    {
        return array_filter([
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'cachedInputTokens' => $this->cachedInputTokens,
            'cacheCreationTokens' => $this->cacheCreationTokens,
            'images' => $this->images,
            'audioSeconds' => $this->audioSeconds,
        ], static fn (int|float|null $v): bool => $v !== null);
    }
}
