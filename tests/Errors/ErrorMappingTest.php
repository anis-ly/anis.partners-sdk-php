<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Errors;

use Anis\Partners\AnisPartnersException;
use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Errors\AuthorizationException;
use Anis\Partners\Errors\DependencyUnavailableException;
use Anis\Partners\Errors\EnrollmentRefusedException;
use Anis\Partners\Errors\ErrorCode;
use Anis\Partners\Errors\IdempotencyConflictException;
use Anis\Partners\Errors\InsufficientBalanceException;
use Anis\Partners\Errors\InvalidCredentialsException;
use Anis\Partners\Errors\LimitExceededException;
use Anis\Partners\Errors\OrderRefusalOutcome;
use Anis\Partners\Errors\OutOfStockException;
use Anis\Partners\Errors\PriceChangedException;
use Anis\Partners\Errors\RateLimitedException;
use Anis\Partners\Errors\ReplayDetectedException;
use Anis\Partners\Errors\ResourceNotFoundException;
use Anis\Partners\Errors\ValidationFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Checks that public refusal codes keep their typed partner handling decisions. */
final class ErrorMappingTest extends TestCase
{
    /** @return iterable<string, array{ErrorCode, class-string<AnisApiException>, OrderRefusalOutcome}> */
    public static function publicCodeDecisions(): iterable
    {
        $decisions = [
            ErrorCode::InsufficientBalance->value => [InsufficientBalanceException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::PriceChanged->value => [PriceChangedException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::QuantityUnavailable->value => [OutOfStockException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::CardUnavailable->value => [OutOfStockException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::OwnerLimitExceeded->value => [LimitExceededException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::DailyLimitExceeded->value => [LimitExceededException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::AllowedDebtConsentRequired->value => [AnisApiException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::PurchaseNotAllowed->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::WalletDisabled->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::WalletExpired->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::BusinessSubscriptionRequired->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::AccountInactive->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::BindingNotAuthorized->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::SourceIpNotAllowed->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::CurrencyNotSupported->value => [ValidationFailedException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::IdempotencyConflict->value => [IdempotencyConflictException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::InvalidContentDigest->value => [AnisApiException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::ValidationFailed->value => [ValidationFailedException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::ResourceNotFound->value => [ResourceNotFoundException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::CardNotFound->value => [ResourceNotFoundException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::DependencyUnavailable->value => [DependencyUnavailableException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::RequestTimeout->value => [DependencyUnavailableException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::InternalError->value => [DependencyUnavailableException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::OperationProcessing->value => [AnisApiException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::ReplayDetected->value => [ReplayDetectedException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::RateLimited->value => [RateLimitedException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::InsufficientScope->value => [AuthorizationException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::InvalidCredentials->value => [InvalidCredentialsException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::SignatureExpired->value => [InvalidCredentialsException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::WalletNotGranted->value => [ResourceNotFoundException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::MalformedSignedRequest->value => [AnisApiException::class, OrderRefusalOutcome::Unknown],
            ErrorCode::RevealNotAllowed->value => [AuthorizationException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::InvoiceRevealLimitExceeded->value => [AnisApiException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::InvitationInvalid->value => [EnrollmentRefusedException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::ChallengeExpired->value => [EnrollmentRefusedException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::KeyProofInvalid->value => [EnrollmentRefusedException::class, OrderRefusalOutcome::NotPlaced],
            ErrorCode::KeyDuplicate->value => [EnrollmentRefusedException::class, OrderRefusalOutcome::NotPlaced],
        ];
        foreach ($decisions as $value => [$class, $outcome]) {
            yield $value => [ErrorCode::from($value), $class, $outcome];
        }
    }

    #[Test]
    public function it_has_a_decision_for_every_published_code(): void
    {
        self::assertCount(37, array_filter(ErrorCode::cases(), static fn(ErrorCode $code): bool => $code !== ErrorCode::Unknown));
        self::assertCount(37, iterator_to_array(self::publicCodeDecisions()));
    }

    /** @param class-string<AnisApiException> $exceptionClass */
    #[Test]
    #[DataProvider('publicCodeDecisions')]
    public function it_maps_a_code_to_its_typed_exception_and_order_outcome(
        ErrorCode $code,
        string $exceptionClass,
        OrderRefusalOutcome $outcome,
    ): void {
        $error = AnisApiException::fromResponse(409, [], json_encode([
            'status' => 409,
            'code' => $code->value,
            'requestId' => 'req-1',
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf($exceptionClass, $error);
        self::assertInstanceOf(AnisPartnersException::class, $error);
        self::assertSame($code, $error->errorCode);
        self::assertSame($code, $error->errorCode());
        self::assertSame(409, $error->getCode());
        self::assertSame($code->value, $error->rawCode);
        self::assertSame('req-1', $error->requestId);
        self::assertSame($outcome, $error->orderOutcome);
    }

    #[Test]
    public function it_keeps_an_unknown_future_code_open_for_order_recovery(): void
    {
        $error = AnisApiException::fromResponse(409, [], '{"code":"future_code"}');

        self::assertSame(ErrorCode::Unknown, $error->errorCode);
        self::assertSame('future_code', $error->rawCode);
        self::assertSame(OrderRefusalOutcome::Unknown, $error->orderOutcome);
        self::assertFalse($error->isRetryable);
    }

    /** @return iterable<string, array{string}> */
    public static function replayedRefusals(): iterable
    {
        yield 'known final refusal' => ['invalid_credentials'];
        yield 'retryable refusal' => ['internal_error'];
        yield 'unknown future refusal' => ['future_code'];
    }

    #[Test]
    #[DataProvider('replayedRefusals')]
    public function it_closes_a_replayed_refusal_regardless_of_its_code(string $code): void
    {
        $error = AnisApiException::fromResponse(409, ['Idempotency-Replayed' => ['true']], json_encode([
            'code' => $code,
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($error->isReplayed);
        self::assertSame(OrderRefusalOutcome::NotPlaced, $error->orderOutcome);
    }

    #[Test]
    public function it_turns_an_unreadable_signed_problem_into_a_refusal(): void
    {
        $error = AnisApiException::fromResponse(502, [], '<html>unavailable</html>');

        self::assertInstanceOf(DependencyUnavailableException::class, $error);
        self::assertSame(ErrorCode::InternalError, $error->errorCode);
        self::assertSame(502, $error->status);
        self::assertSame(OrderRefusalOutcome::Unknown, $error->orderOutcome);
    }

    #[Test]
    public function it_treats_a_json_array_as_an_unreadable_problem_object(): void
    {
        $error = AnisApiException::fromResponse(502, [], '[]');

        self::assertSame(ErrorCode::InternalError, $error->errorCode);
        self::assertSame('internal_error', $error->rawCode);
        self::assertSame(502, $error->getCode());
    }
}
