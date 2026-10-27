<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says where a direct recharge stands, merged as the app shows it. */
enum DirectRechargeStatus: string
{
    case Unknown = 'unknown';
    case AwaitingPayment = 'awaitingPayment';
    case PaymentFailed = 'paymentFailed';
    case Pending = 'pending';
    case InProgress = 'inProgress';
    case SuspendedDataError = 'suspendedDataError';
    case Completed = 'completed';
    case RejectedAwaitingRefund = 'rejectedAwaitingRefund';
    case RejectedWithRefund = 'rejectedWithRefund';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
