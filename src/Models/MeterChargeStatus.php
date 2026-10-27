<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says where a meter charge stands. */
enum MeterChargeStatus: string
{
    case Unknown = 'unknown';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
