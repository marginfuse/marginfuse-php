<?php

declare(strict_types=1);

namespace MarginFuse;

/** A verdict. Enforce on this alone. */
enum DecisionAction: string
{
    case Allow = 'allow';
    case Downgrade = 'downgrade';
    case TopupRequired = 'topup_required';
    case Block = 'block';

    /**
     * An action a newer server sends and this version cannot enforce resolves
     * to Allow: an unrecognised value must never silently become a block.
     */
    public static function fromWire(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Allow;
    }
}
