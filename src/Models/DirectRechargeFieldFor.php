<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says whether a field describes the recipient or the sender. */
enum DirectRechargeFieldFor: string
{
    case Unknown = 'unknown';
    case Recipient = 'recipient';
    case Sender = 'sender';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
