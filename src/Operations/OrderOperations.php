<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Errors\OrderRefusalOutcome;
use Anis\Partners\Errors\OrderRefusals;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\Order;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\OrderNotPlaced;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Models\OrderProcessing;
use Anis\Partners\Models\OrderReplayed;
use Anis\Partners\Models\OrderResult;
use Anis\Partners\Observability\AnisPartnersTelemetry;
use Anis\Partners\Observability\Log;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureProfile;
use Psr\Log\LoggerInterface;

/** Places and safely resumes orders under the caller's durable operation identifier. */
final class OrderOperations extends AbstractOperations
{
    /** Keeps normal recovery waits long enough to avoid repeatedly asking Anis before work advances. */
    private const DEFAULT_DELAY_SECONDS = 5;

    /** Allows time to restore credentials or access before a door refusal is resumed. */
    private const DOOR_DELAY_SECONDS = 60;

    /** @internal */
    public function __construct(private readonly PartnerTransport $transport, private readonly ?LoggerInterface $logger = null) {}

    /** Places an order; the operation ID must be created and stored by the caller before this call. */
    public function create(string $walletId, string $operationId, CreateOrderRequest $order): OrderResult
    {
        return $this->send($walletId, $operationId, $order, false);
    }

    /** Re-drives the same order body with the same operation ID and a fresh request signature. */
    public function resume(string $walletId, string $operationId, CreateOrderRequest $order): OrderResult
    {
        return $this->send($walletId, $operationId, $order, true);
    }

    /** Reads order state without dispatching work or returning credentials. */
    public function get(string $operationId): Order
    {
        $id = Uuid::canonical($operationId);
        /** @var Order */
        return $this->fetchModel($this->transport, '/v1/orders/{operationId}', 'v1/orders/' . $id, [Order::class, 'fromArray']);
    }

    private function send(string $walletId, string $operationId, CreateOrderRequest $order, bool $resuming): OrderResult
    {
        self::guard($order);
        $wallet = Uuid::canonical($walletId);
        $operation = Uuid::canonical($operationId);
        $result = null;
        $outcome = 'unknown';
        $reason = null;
        $delay = self::DEFAULT_DELAY_SECONDS;
        try {
            $response = $this->transport->request(
                'POST',
                '/v1/wallets/{walletId}/orders',
                'v1/wallets/' . $wallet . '/orders',
                SignatureProfile::OrderMutation,
                $order->toJson(),
                $operation,
            );
            $value = Order::fromArray($response->json);
            if ($response->status === 202) {
                $result = new OrderProcessing($value, self::retryAfter($response->header('Retry-After')) ?? self::DEFAULT_DELAY_SECONDS, $response->header('Location'));
                $outcome = 'processing';
            } elseif (($value->soldCards ?? []) !== []) {
                $result = new OrderCompleted($value);
                $outcome = 'completed';
            } elseif (self::isReplayed($response->headerValues('Idempotency-Replayed'))) {
                $result = new OrderReplayed($value);
                $outcome = 'replayed';
            } else {
                $result = new OrderCompleted($value);
                $outcome = 'completed';
            }
        } catch (AnisApiException $refusal) {
            if ($refusal->orderOutcome === OrderRefusalOutcome::NotPlaced && ($refusal->isReplayed || !$resuming)) {
                $result = new OrderNotPlaced($operation, $refusal);
                $outcome = 'not_placed';
            } else {
                $delay = $refusal->retryAfter ?? (OrderRefusals::refusedAtTheDoor($refusal->errorCode) ? self::DOOR_DELAY_SECONDS : self::DEFAULT_DELAY_SECONDS);
                $reason = self::reason($refusal);
                $result = new OrderOutcomeUnknown($operation, $delay, $refusal);
            }
        } catch (RequestSigningException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $reason = self::reason($exception);
            $result = new OrderOutcomeUnknown($operation, self::DEFAULT_DELAY_SECONDS, $exception);
        }

        // Reporting is deliberately outside purchase handling: host instrumentation cannot replace a verified result.
        AnisPartnersTelemetry::orderOutcome($outcome, $reason);
        Log::write($this->logger ?? new \Psr\Log\NullLogger(), $outcome === 'unknown' ? 'warning' : 'info', $outcome === 'unknown' ? 'Anis order outcome unknown; resume with the same operation id' : 'Anis order outcome', $outcome === 'unknown' ? 1008 : 1004, ['operation_id' => $operation, 'outcome' => $outcome] + ($reason === null ? [] : ['reason' => $reason]));

        return $result;
    }

    /** Checks arithmetic and sale constraints before a signed request can leave the process. */
    private static function guard(CreateOrderRequest $order): void
    {
        if ($order->quantity < 1) {
            throw new \InvalidArgumentException('An order must be for at least one card.');
        }
        if ($order->expectedUnitPrice->thousandths <= 0) {
            throw new \InvalidArgumentException('ExpectedUnitPrice must be greater than zero.');
        }
        if ($order->expectedUnitPrice->currency !== $order->expectedTotal->currency) {
            throw new \InvalidArgumentException('ExpectedUnitPrice and ExpectedTotal must carry the same currency.');
        }
        if ($order->expectedUnitPrice->multiply($order->quantity)->thousandths !== $order->expectedTotal->thousandths) {
            throw new \InvalidArgumentException('ExpectedTotal must equal unit price multiplied by quantity.');
        }
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

    private static function reason(\Throwable $exception): string
    {
        if ($exception instanceof AnisApiException && $exception->errorCode->value === 'internal_error' && $exception->problem->title === 'Empty body') {
            return 'empty_body';
        }
        if ($exception instanceof AnisApiException) {
            return $exception->rawCode ?? 'refused';
        }
        if ($exception instanceof \Anis\Partners\Verification\UnverifiableResponseException) {
            return 'unverifiable';
        }
        if ($exception instanceof \Psr\Http\Client\NetworkExceptionInterface) {
            return 'connection';
        }
        if ($exception instanceof \Psr\Http\Client\ClientExceptionInterface) {
            return 'connection';
        }

        return 'other';
    }

    private static function retryAfter(?string $value): ?int
    {
        return $value !== null && preg_match('/\A[0-9]+\z/D', $value) === 1 ? (int) $value : null;
    }
}
