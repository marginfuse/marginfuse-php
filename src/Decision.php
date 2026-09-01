<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * A verdict from MarginFuse.
 *
 * `$degraded` is true when MarginFuse could not reach a verdict and the request
 * was allowed through unprotected. `$id` is null in that case, which is exactly
 * why enforcement must depend on `$action` alone.
 */
final readonly class Decision
{
    public function __construct(
        public DecisionAction $action,
        public string $model,
        public string $provider,
        public ?string $id = null,
        public ?string $topupContext = null,
        public bool $degraded = false,
        public ?string $degradedReason = null,
    ) {
    }
}
