<?php

declare(strict_types=1);

namespace MarginFuse;

/** What happened to a provider call. */
enum Outcome: string
{
    case Success = 'success';
    case ProviderError = 'provider_error';
    case AppCancelled = 'app_cancelled';
    case Timeout = 'timeout';
}
