<?php

declare(strict_types=1);

namespace MarginFuse;

/** What the application actually did with a decision. */
enum Acknowledgment: string
{
    case ProceededAsRequested = 'proceeded_as_requested';
    case UsedDowngradeModel = 'used_downgrade_model';
    case PresentedTopup = 'presented_topup';
    case BlockedBeforeProviderCall = 'blocked_before_provider_call';
    case FailedToApply = 'failed_to_apply';
}
