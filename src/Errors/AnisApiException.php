<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

use Anis\Partners\AnisPartnersException;
use Anis\Partners\Internal\RetryAfter;
use Anis\Partners\Models\Problem;

/** Represents a verified refusal; branch on its machine code, not localized message text. */
class AnisApiException extends \RuntimeException implements AnisPartnersException
{
    public readonly ErrorCode $errorCode;
    public readonly ?string $rawCode;
    public readonly int $status;
    public readonly ?string $requestId;
    public readonly ?string $typeUri;
    public readonly ?int $retryAfter;
    public readonly bool $isReplayed;
    public readonly bool $isRetryable;
    public readonly OrderRefusalOutcome $orderOutcome;
    public readonly Problem $problem;

    /**
     * Builds a refusal from its verified body and semantic response headers.
     *
     */
    public function __construct(Problem $problem, int $status, ?int $retryAfter = null, bool $isReplayed = false)
    {
        $this->problem = $problem;
        $this->errorCode = ErrorCode::parse($problem->code);
        $this->rawCode = $problem->code;
        $this->status = $status;
        $this->requestId = $problem->requestId;
        $this->typeUri = $problem->type;
        $this->retryAfter = $retryAfter;
        $this->isReplayed = $isReplayed;
        $this->isRetryable = $this->errorCode->isRetryable();
        $this->orderOutcome = $isReplayed ? OrderRefusalOutcome::NotPlaced : OrderRefusals::outcomeOf($this->errorCode);

        parent::__construct(self::buildMessage($problem, $status, $isReplayed), $status);
    }

    /** Returns the typed Anis problem code while getCode() retains the HTTP status. */
    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * Parses verified refusal data, falling back to internal_error when its problem fields are unreadable.
     * @param array<string, mixed> $headers
     */
    public static function fromResponse(int $status, array $headers, string $body): self
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $data = $decoded instanceof \stdClass ? get_object_vars($decoded) : null;
        } catch (\JsonException) {
            $data = null;
        }
        try {
            $problem = is_array($data)
                ? Problem::fromArray($data)
                : new Problem('about:blank', 'Unreadable problem', $status, ErrorCode::InternalError->value);
        } catch (\UnexpectedValueException) {
            $problem = new Problem('about:blank', 'Unreadable problem', $status, ErrorCode::InternalError->value);
        }

        $replayed = false;
        $retryAfter = null;
        foreach ($headers as $name => $values) {
            if (strtolower($name) !== 'idempotency-replayed') {
                continue;
            }
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_string($value)) {
                    foreach (explode(',', $value) as $individualValue) {
                        if (strtolower(trim($individualValue)) === 'true') {
                            $replayed = true;
                        }
                    }
                }
            }
        }
        foreach ($headers as $name => $values) {
            if (strtolower($name) !== 'retry-after') {
                continue;
            }
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_string($value)) {
                    $retryAfter = RetryAfter::parse(trim($value));
                    break;
                }
            }
        }

        $exception = match ($problem->code === null ? ErrorCode::Unknown : ErrorCode::parse($problem->code)) {
            ErrorCode::InsufficientBalance => InsufficientBalanceException::class,
            ErrorCode::PriceChanged => PriceChangedException::class,
            ErrorCode::QuantityUnavailable, ErrorCode::CardUnavailable => OutOfStockException::class,
            ErrorCode::IdempotencyConflict => IdempotencyConflictException::class,
            ErrorCode::RateLimited => RateLimitedException::class,
            ErrorCode::OwnerLimitExceeded, ErrorCode::DailyLimitExceeded => LimitExceededException::class,
            ErrorCode::InvalidCredentials, ErrorCode::SignatureExpired => InvalidCredentialsException::class,
            ErrorCode::ReplayDetected => ReplayDetectedException::class,
            ErrorCode::InsufficientScope,
            ErrorCode::SourceIpNotAllowed,
            ErrorCode::BindingNotAuthorized,
            ErrorCode::AccountInactive,
            ErrorCode::BusinessSubscriptionRequired,
            ErrorCode::WalletDisabled,
            ErrorCode::WalletExpired,
            ErrorCode::PurchaseNotAllowed,
            ErrorCode::RevealNotAllowed => AuthorizationException::class,
            ErrorCode::ResourceNotFound, ErrorCode::CardNotFound, ErrorCode::WalletNotGranted => ResourceNotFoundException::class,
            ErrorCode::ValidationFailed, ErrorCode::CurrencyNotSupported => ValidationFailedException::class,
            ErrorCode::DependencyUnavailable, ErrorCode::RequestTimeout, ErrorCode::InternalError => DependencyUnavailableException::class,
            ErrorCode::InvitationInvalid, ErrorCode::ChallengeExpired, ErrorCode::KeyProofInvalid, ErrorCode::KeyDuplicate => EnrollmentRefusedException::class,
            default => self::class,
        };

        return new $exception($problem, $status, $retryAfter, $replayed);
    }

    /** Represents a verified success that supplied no JSON body, whose result cannot be safely inferred. */
    public static function emptyBody(int $status): self
    {
        return new EmptyBodyException($status);
    }

    private static function buildMessage(Problem $problem, int $status, bool $isReplayed): string
    {
        return 'Anis returned ' . $status . ' ' . ($problem->code ?? '')
            . ($isReplayed ? ' (the recorded answer of an earlier attempt with this operation id)' : '')
            . ($problem->requestId === null ? '' : ' (request ' . $problem->requestId . ')')
            . ($status >= 200 && $status < 300 && $problem->code === ErrorCode::InternalError->value ? ' Empty body.' : '')
            . '. Branch on the code, not on this message.';
    }
}
