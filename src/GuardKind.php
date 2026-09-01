<?php

declare(strict_types=1);

namespace MarginFuse;

/** What guard did. */
enum GuardKind: string
{
    case Completed = 'completed';
    case Blocked = 'blocked';
    case TopupRequired = 'topup_required';
}
