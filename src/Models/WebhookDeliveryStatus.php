<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says where one notification delivery stands. */
enum WebhookDeliveryStatus: string
{
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Retrying = 'retrying';
    case Failed = 'failed';
    case WaitingForProjection = 'waitingForProjection';
    case WaitingForEndpoint = 'waitingForEndpoint';
    case Cancelled = 'cancelled';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
