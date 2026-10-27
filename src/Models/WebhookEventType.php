<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Names what happened to an operation or endpoint; the operational types come first, the two controls last. */
enum WebhookEventType: string
{
    case Unknown = 'unknown';
    case MeterChargeSucceeded = 'meterCharge.succeeded';
    case MeterChargeFailed = 'meterCharge.failed';
    case DirectRechargePaid = 'directRecharge.paid';
    case DirectRechargePaymentFailed = 'directRecharge.paymentFailed';
    case DirectRechargeSuspended = 'directRecharge.suspended';
    case DirectRechargeCompleted = 'directRecharge.completed';
    case DirectRechargeRejected = 'directRecharge.rejected';
    case DirectRechargePartiallyRefunded = 'directRecharge.partiallyRefunded';
    case EndpointVerification = 'webhook_endpoint.verification';
    case EndpointTest = 'webhook_endpoint.test';

    /** Maps a type this SDK does not know to Unknown; the raw spelling is kept next to it. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }

    /** Says whether the type reports on a meter charge or a direct recharge. */
    public function isOperational(): bool
    {
        return $this !== self::Unknown && $this !== self::EndpointVerification && $this !== self::EndpointTest;
    }
}
