<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** @internal Applies the published order-recovery rules to a refusal code. */
final class OrderRefusals
{
    /** Returns Unknown for any code that may follow an earlier attempt that is still completing. */
    public static function outcomeOf(ErrorCode $code): OrderRefusalOutcome
    {
        return match ($code) {
            ErrorCode::DependencyUnavailable,
            ErrorCode::RequestTimeout,
            ErrorCode::InternalError,
            ErrorCode::OperationProcessing,
            ErrorCode::ReplayDetected,
            ErrorCode::RateLimited,
            ErrorCode::Unknown => OrderRefusalOutcome::Unknown,
            default => self::refusedAtTheDoor($code) ? OrderRefusalOutcome::Unknown : OrderRefusalOutcome::NotPlaced,
        };
    }

    /** Identifies access or signature refusals that can race an earlier order attempt. */
    public static function refusedAtTheDoor(ErrorCode $code): bool
    {
        return in_array($code, [
            ErrorCode::InvalidCredentials,
            ErrorCode::SignatureExpired,
            ErrorCode::InsufficientScope,
            ErrorCode::WalletNotGranted,
            ErrorCode::MalformedSignedRequest,
        ], true);
    }
}
