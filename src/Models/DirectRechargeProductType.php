<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says how a direct recharge product pays out. */
enum DirectRechargeProductType: string
{
    case Unknown = 'unknown';
    case External = 'external';
    case Billing = 'billing';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
