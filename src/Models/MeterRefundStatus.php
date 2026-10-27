<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says whether the money of a failed meter charge is back. */
enum MeterRefundStatus: string
{
    case Unknown = 'unknown';
    case Refunded = 'refunded';
    case NotCharged = 'notCharged';
    case Pending = 'pending';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
