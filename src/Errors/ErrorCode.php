<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** @generated from contracts/error-catalogue.json by tools/generate-errors.php; do not edit. */
enum ErrorCode: string
{
    case Unknown = 'unknown';
    case AccountInactive = 'account_inactive';
    case AllowedDebtConsentRequired = 'allowed_debt_consent_required';
    case BindingNotAuthorized = 'binding_not_authorized';
    case BusinessSubscriptionRequired = 'business_subscription_required';
    case CardNotFound = 'card_not_found';
    case CardUnavailable = 'card_unavailable';
    case ChallengeExpired = 'challenge_expired';
    case CurrencyNotSupported = 'currency_not_supported';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case DependencyUnavailable = 'dependency_unavailable';
    case IdempotencyConflict = 'idempotency_conflict';
    case InsufficientBalance = 'insufficient_balance';
    case InsufficientScope = 'insufficient_scope';
    case InternalError = 'internal_error';
    case InvalidContentDigest = 'invalid_content_digest';
    case InvalidCredentials = 'invalid_credentials';
    case InvitationInvalid = 'invitation_invalid';
    case InvoiceRevealLimitExceeded = 'invoice_reveal_limit_exceeded';
    case KeyDuplicate = 'key_duplicate';
    case KeyProofInvalid = 'key_proof_invalid';
    case MalformedSignedRequest = 'malformed_signed_request';
    case OperationProcessing = 'operation_processing';
    case OwnerLimitExceeded = 'owner_limit_exceeded';
    case PriceChanged = 'price_changed';
    case PurchaseNotAllowed = 'purchase_not_allowed';
    case QuantityUnavailable = 'quantity_unavailable';
    case RateLimited = 'rate_limited';
    case ReplayDetected = 'replay_detected';
    case RequestTimeout = 'request_timeout';
    case ResourceNotFound = 'resource_not_found';
    case RevealNotAllowed = 'reveal_not_allowed';
    case SignatureExpired = 'signature_expired';
    case SourceIpNotAllowed = 'source_ip_not_allowed';
    case ValidationFailed = 'validation_failed';
    case WalletDisabled = 'wallet_disabled';
    case WalletExpired = 'wallet_expired';
    case WalletNotGranted = 'wallet_not_granted';

    /** Resolves wire values leniently so a future code does not break response handling. */
    public static function parse(?string $value): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        return self::tryFrom($value) ?? self::Unknown;
    }

    /** Reports only catalogue-marked retryable values; unknown codes remain conservative. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::DependencyUnavailable => true,
            self::InternalError => true,
            self::OperationProcessing => true,
            self::RateLimited => true,
            self::ReplayDetected => true,
            self::RequestTimeout => true,
            self::SignatureExpired => true,
            default => false,
        };
    }
}
