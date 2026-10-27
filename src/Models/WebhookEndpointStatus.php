<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says where the webhook endpoint stands. */
enum WebhookEndpointStatus: string
{
    case Unknown = 'unknown';
    case NotConfigured = 'notConfigured';
    case PendingVerification = 'pendingVerification';
    case Verified = 'verified';
    case Disabled = 'disabled';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
