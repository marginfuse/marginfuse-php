<?php

declare(strict_types=1);

namespace MarginFuse;

/** The result of the whole guard loop. */
final readonly class GuardOutcome
{
    public function __construct(
        public GuardKind $kind,
        public Decision $decision,
        public mixed $result = null,
    ) {
    }
}
