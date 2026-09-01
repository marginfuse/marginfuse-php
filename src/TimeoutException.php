<?php

declare(strict_types=1);

namespace MarginFuse;

/**
 * The request did not answer in time.
 *
 * Never reaches application code: decide() catches it and fails open, which is
 * the whole point of naming it separately from a transport failure.
 */
final class TimeoutException extends \RuntimeException
{
}
