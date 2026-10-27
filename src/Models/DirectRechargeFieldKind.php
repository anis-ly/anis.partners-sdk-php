<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says what kind of value a recipient field takes. */
enum DirectRechargeFieldKind: string
{
    case Unknown = 'unknown';
    case Text = 'text';
    case List = 'list';
    case Note = 'note';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
