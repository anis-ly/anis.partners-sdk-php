<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Errors\ErrorCode;
use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Errors\OrderRefusalOutcome;
use Anis\Partners\Errors\OrderRefusals;
use Anis\Partners\Internal\RetryAfter;
use Anis\Partners\Observability\Log;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureProfile;
use Psr\Log\LoggerInterface;

/**
 * @internal
 * Sends one meter charge or direct recharge create and decides, with the same rules as an order, what the answer proves.
 *
 * The body is serialised by the caller once; these bytes are signed and sent, and a resume repeats them with a fresh
 * signature under the same operation id. Only a refusal that proves nothing was placed closes the operation.
 */
final class ProductCreateFlow
{
    /** Keeps normal recovery waits long enough to avoid repeatedly asking Anis before work advances. */
    private const DEFAULT_DELAY_SECONDS = 5;

    /** Allows time to restore access before a door refusal is resumed. */
    private const DOOR_DELAY_SECONDS = 60;

    public const FINAL = 'final';
    public const OPEN = 'open';
    public const UNRESOLVED = 'unresolved';

    public function __construct(private readonly PartnerTransport $transport, private readonly ?LoggerInterface $logger = null) {}

    /**
     * @param callable(array<array-key, mixed>): string $acceptedFrom Reads the exact three-member processing answer and returns its operation id.
     * @param callable(array<array-key, mixed>): object $readFrom Reads the full owner read.
     * @param callable(object): array{string, string} $classify Returns the read's operation id and FINAL, OPEN or UNRESOLVED.
     * @param string $expectedLocation The read path Anis answers with, the only Location that is passed on.
     * @param string $product Business name used in log lines.
     */
    public function send(
        string $template,
        string $path,
        string $body,
        string $operationId,
        bool $resuming,
        callable $acceptedFrom,
        callable $readFrom,
        callable $classify,
        string $expectedLocation,
        string $product,
    ): ProductCreateOutcome {
        $outcome = null;
        $name = 'unknown';
        $reason = null;
        $conflict = false;
        try {
            $response = $this->transport->request('POST', $template, $path, SignatureProfile::OrderMutation, $body, $operationId);
            if ($response->status === 202) {
                $outcome = self::accepted($response, $operationId, $expectedLocation, $acceptedFrom);
                $name = 'processing';
            } else {
                $delay = self::retryDelay($response);
                $location = $response->header('Location');
                $location = $location === $expectedLocation ? $location : null;
                try {
                    $read = $readFrom($response->json);
                } catch (\Throwable) {
                    throw new MalformedResponseException('The verified answer does not match the read model.');
                }
                [$readId, $state] = $classify($read);
                if ($readId !== $operationId) {
                    throw new MalformedResponseException('The verified answer names another operation than the one sent.');
                }
                if ($state === self::FINAL) {
                    $outcome = ProductCreateOutcome::closed($operationId, $read, self::isReplayed($response->headerValues('Idempotency-Replayed')));
                    $name = 'final';
                } elseif ($state === self::OPEN) {
                    $outcome = ProductCreateOutcome::open($operationId, $delay, $location, $read);
                    $name = 'processing';
                } else {
                    throw new MalformedResponseException('The verified answer carries a status this SDK does not know.');
                }
            }
        } catch (AnisApiException $refusal) {
            if (self::closesOperation($refusal, $resuming)) {
                $outcome = ProductCreateOutcome::refused($operationId, $refusal);
                $name = 'not_placed';
            } else {
                $delay = $refusal->retryAfter ?? (OrderRefusals::refusedAtTheDoor($refusal->errorCode) ? self::DOOR_DELAY_SECONDS : self::DEFAULT_DELAY_SECONDS);
                $reason = $refusal->rawCode ?? 'refused';
                $conflict = $refusal->errorCode === ErrorCode::IdempotencyConflict;
                $outcome = ProductCreateOutcome::unknown($operationId, $delay, $refusal);
            }
        } catch (RequestSigningException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $reason = $exception instanceof \Psr\Http\Client\ClientExceptionInterface ? 'connection' : ($exception instanceof MalformedResponseException ? 'unverifiable' : 'other');
            $outcome = ProductCreateOutcome::unknown($operationId, self::DEFAULT_DELAY_SECONDS, $exception);
        }

        if ($name !== 'not_placed') {
            $message = $name === 'unknown'
                ? sprintf('Anis could not determine %s operation %s: %s; %s, never a new one.', $product, $operationId, $reason ?? 'unknown', $conflict ? 'read it by its operation id (get) before any new attempt' : 'resume it with the same operation id')
                : sprintf('Anis %s outcome %s for operation %s', $product, $name, $operationId);
            Log::write($this->logger ?? new \Psr\Log\NullLogger(), $name === 'unknown' ? 'warning' : 'info', $message, $name === 'unknown' ? 1008 : 1004, ['operation_id' => $operationId, 'outcome' => $name] + ($reason === null ? [] : ['reason' => $reason]));
        }

        return $outcome;
    }

    /**
     * Reads the same verified 202 acknowledgement for creates and recipient changes, without fetching a read.
     * @param callable(array<array-key, mixed>): string $acceptedFrom Reads the strict wire body and returns its operation id.
     */
    public static function accepted(TransportResponse $response, string $operationId, string $expectedLocation, callable $acceptedFrom, bool $requireLocation = false): ProductCreateOutcome
    {
        if ($response->status !== 202 || ($requireLocation && $response->headerValues('Location') !== [$expectedLocation])) {
            throw new MalformedResponseException('The verified answer must be 202 with the operation read Location.');
        }
        try {
            $accepted = $acceptedFrom($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The verified answer is not the three-member processing answer.');
        }
        if ($accepted !== $operationId) {
            throw new MalformedResponseException('The verified answer names another operation than the one sent.');
        }
        $location = $response->header('Location');
        $location = $location === $expectedLocation ? $location : null;

        return ProductCreateOutcome::open($operationId, self::retryDelay($response), $location, null);
    }

    private static function retryDelay(TransportResponse $response): int
    {
        return RetryAfter::parse($response->header('Retry-After')) ?? self::DEFAULT_DELAY_SECONDS;
    }

    /**
     * A refusal closes the operation when Anis proves nothing was placed: a definitive refusal of the first create or a
     * recorded replay of one. idempotency_conflict never proves it: the original request may already have been accepted
     * and charged, so it stays unknown and the caller reads the operation by its id (get) before any new attempt.
     */
    private static function closesOperation(AnisApiException $refusal, bool $resuming): bool
    {
        if ($refusal->errorCode === ErrorCode::IdempotencyConflict) {
            return false;
        }

        return $refusal->orderOutcome === OrderRefusalOutcome::NotPlaced && ($refusal->isReplayed || !$resuming);
    }

    /** @param list<string> $values */
    private static function isReplayed(array $values): bool
    {
        foreach ($values as $value) {
            foreach (explode(',', $value) as $individualValue) {
                if (strtolower(trim($individualValue)) === 'true') {
                    return true;
                }
            }
        }

        return false;
    }
}
