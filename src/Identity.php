<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * What MarginFuse recorded for a customer.
 *
 * `$ok` is the only field to branch on. When it is false the call changed
 * nothing and `$error` says what happened; the SDK still did not throw.
 *
 * Unlike track(), identify() reports its failures. track() has a safe default,
 * retry later, and "I could not record what this customer pays" has none: a
 * wrong plan is a wrong margin.
 */
final readonly class Identity
{
    public function __construct(
        public bool $ok,
        public ?string $customerId = null,
        public ?string $plan = null,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public ?string $error = null,
    ) {
    }
}
