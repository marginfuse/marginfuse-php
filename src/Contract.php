<?php

declare(strict_types=1);

namespace MarginFuse;

/** What this build was verified against. */
final class Contract
{
    /**
     * The version of the shared SDK contract this build passed.
     *
     * Package versions differ per language, because each tracks its own
     * breaking changes: a rename in Python must not tell PHP users something
     * broke. What makes the SDKs interchangeable is this, not the package
     * version. Two SDKs reporting the same contract version have passed the
     * same scenarios and the same vectors.
     *
     * @see https://github.com/marginfuse/sdk-contract
     */
    public const VERSION = 1;

    private function __construct()
    {
    }
}
