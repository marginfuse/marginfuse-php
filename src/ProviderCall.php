<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * What your callback did, handed back to guard so it can be reported.
 *
 * `$costUsd` is a decimal string, not a float: money that round-trips through a
 * floating point number stops being what the provider charged.
 */
final readonly class ProviderCall
{
    public function __construct(
        public Usage $usage = new Usage(),
        public mixed $result = null,
        public ?string $costUsd = null,
        public Outcome $outcome = Outcome::Success,
    ) {
    }
}
