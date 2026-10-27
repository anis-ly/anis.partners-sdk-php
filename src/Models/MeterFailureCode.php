<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Names why a meter charge failed; these are values in the body, not HTTP errors. */
enum MeterFailureCode: string
{
    case Unknown = 'unknown';
    case MeterNumberInvalid = 'meter_number_invalid';
    case MeterNotWhitelisted = 'meter_not_whitelisted';
    case MeterNotLinked = 'meter_not_linked';
    case MeterRemoved = 'meter_removed';
    case MeterInactive = 'meter_inactive';
    case MeterAccountBlocked = 'meter_account_blocked';
    case MeterDataIncomplete = 'meter_data_incomplete';
    case MeterDebtExceedsPackage = 'meter_debt_exceeds_package';
    case PackageUnavailable = 'package_unavailable';
    case PackageLimitReached = 'package_limit_reached';
    case TokenNotWritten = 'token_not_written';
    case InsufficientBalance = 'insufficient_balance';
    case GecolUnavailable = 'gecol_unavailable';
    case ChargeFailed = 'charge_failed';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
