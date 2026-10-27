<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\CreateMeterChargeRequest;
use Anis\Partners\Models\MeterCharge;
use Anis\Partners\Models\MeterChargeAccepted;
use Anis\Partners\Models\MeterChargeFinal;
use Anis\Partners\Models\MeterChargeNotPlaced;
use Anis\Partners\Models\MeterChargeOutcomeUnknown;
use Anis\Partners\Models\MeterChargeProcessing;
use Anis\Partners\Models\MeterChargeResult;
use Anis\Partners\Models\MeterChargesQuery;
use Anis\Partners\Models\MeterChargeStatus;
use Anis\Partners\Models\MeterChargeSummary;
use Anis\Partners\Models\MeterPackage;
use Anis\Partners\Models\MeterRefundStatus;
use Anis\Partners\Models\Page;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureProfile;
use Psr\Log\LoggerInterface;

/** Lists meter packages, charges a meter under the caller's durable operation id and reads the receipt. */
final class MeterChargeOperations extends AbstractOperations
{
    private const CREATE_TEMPLATE = '/v1/wallets/{walletId}/meter-charges';

    private readonly ProductCreateFlow $flow;

    /** @internal */
    public function __construct(private readonly PartnerTransport $transport, ?LoggerInterface $logger = null)
    {
        $this->flow = new ProductCreateFlow($transport, $logger);
    }

    /**
     * Reads one page of the packages this wallet can buy.
     * @return Page<MeterPackage>
     */
    public function getMeterPackagesPage(string $walletId, ?string $cursor = null): Page
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/meter-packages';

        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/meter-packages', $this->cursor($path, $cursor), [MeterPackage::class, 'fromArray'], true);
    }

    /**
     * Walks every package page for the wallet.
     * @return \Generator<int, MeterPackage>
     */
    public function getMeterPackages(string $walletId): \Generator
    {
        return $this->walk(fn(?string $cursor): Page => $this->getMeterPackagesPage($walletId, $cursor));
    }

    /**
     * Reads one page of this application's meter charges for the wallet, newest first, without receipts.
     * @return Page<MeterChargeSummary>
     */
    public function getMeterChargesPage(string $walletId, ?MeterChargesQuery $query = null): Page
    {
        $query ??= new MeterChargesQuery();
        $path = $this->withQuery('v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/meter-charges', [
            'status' => $query->status?->value,
            'meterNumber' => $query->meterNumber,
            'from' => self::utc($query->from),
            'to' => self::utc($query->to),
            'cursor' => $query->cursor,
            'pageSize' => self::pageSize($query->pageSize),
        ]);

        return $this->fetchPage($this->transport, self::CREATE_TEMPLATE, $path, [MeterChargeSummary::class, 'fromArray'], true);
    }

    /**
     * Walks every meter charge page; the cursor in the query only sets where the walk starts.
     * @return \Generator<int, MeterChargeSummary>
     */
    public function getMeterCharges(string $walletId, ?MeterChargesQuery $query = null): \Generator
    {
        $query ??= new MeterChargesQuery();

        return $this->walk(fn(?string $cursor): Page => $this->getMeterChargesPage($walletId, new MeterChargesQuery($query->status, $query->meterNumber, $query->from, $query->to, $cursor, $query->pageSize)), $query->cursor);
    }

    /** Charges a meter; the operation id must be created and stored with the exact request before this call. */
    public function create(string $walletId, string $operationId, CreateMeterChargeRequest $request): MeterChargeResult
    {
        return $this->send($walletId, $operationId, $request, false);
    }

    /** Repeats the same request under the same operation id with a fresh signature; it never creates an id. */
    public function resume(string $walletId, string $operationId, CreateMeterChargeRequest $request): MeterChargeResult
    {
        return $this->send($walletId, $operationId, $request, true);
    }

    /**
     * Reads the charge state this application owns, including tokens once it succeeded.
     *
     * Before Anis has the charge in its read store it answers with the three-member processing answer instead of a read.
     * A recorded refusal of the create answers with the same typed {@see \Anis\Partners\Errors\AnisApiException} as the
     * create (status 409 or 422, `isReplayed` true) and is the definitive answer for this id; an `idempotency_conflict`
     * means no charge of your application exists under it.
     */
    public function get(string $operationId): MeterCharge|MeterChargeAccepted
    {
        $id = Uuid::canonical($operationId, 'operation id');
        $response = $this->transport->request('GET', '/v1/meter-charges/{operationId}', 'v1/meter-charges/' . $id, SignatureProfile::SafeRead);
        try {
            $value = $response->status === 202 ? MeterChargeAccepted::fromArray($response->json) : MeterCharge::fromArray($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The verified meter charge answer does not match the model.');
        }
        if ($value->operationId !== $id) {
            throw new MalformedResponseException('The verified meter charge answer names another operation than the one asked for.');
        }

        return $value;
    }

    private function send(string $walletId, string $operationId, CreateMeterChargeRequest $request, bool $resuming): MeterChargeResult
    {
        $wallet = Uuid::canonical($walletId, 'wallet id');
        $operation = Uuid::canonical($operationId, 'operation id');
        try {
            $body = $request->toJson();
        } catch (\Anis\Partners\Errors\AnisPartnersInvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RequestSigningException($exception);
        }
        $outcome = $this->flow->send(
            self::CREATE_TEMPLATE,
            'v1/wallets/' . $wallet . '/meter-charges',
            $body,
            $operation,
            $resuming,
            static fn(array $json): string => MeterChargeAccepted::fromArray($json)->operationId,
            [MeterCharge::class, 'fromArray'],
            static function (object $read): array {
                if (!$read instanceof MeterCharge) {
                    return ['', ProductCreateFlow::UNRESOLVED];
                }

                // Final is a succeeded charge, or a failed one whose refund is settled (refunded or notCharged). A failed charge
                // with a pending refund stays open: money was taken and has not landed back yet.
                return [$read->operationId, match ($read->status) {
                    MeterChargeStatus::Succeeded => ProductCreateFlow::FINAL,
                    MeterChargeStatus::Failed => $read->refund?->status === MeterRefundStatus::Pending ? ProductCreateFlow::OPEN : ProductCreateFlow::FINAL,
                    MeterChargeStatus::Processing => ProductCreateFlow::OPEN,
                    MeterChargeStatus::Unknown => ProductCreateFlow::UNRESOLVED,
                }];
            },
            '/v1/meter-charges/' . $operation,
            'meter charge',
        );

        return match ($outcome->kind) {
            'final' => new MeterChargeFinal(self::charge($outcome->read), $outcome->replayed),
            'open' => new MeterChargeProcessing($operation, $outcome->delaySeconds, $outcome->location, $outcome->read instanceof MeterCharge ? $outcome->read : null),
            'refused' => new MeterChargeNotPlaced($operation, self::refusal($outcome)),
            default => new MeterChargeOutcomeUnknown($operation, $outcome->delaySeconds, $outcome->cause ?? new MalformedResponseException('The outcome is unknown.')),
        };
    }

    private static function charge(?object $read): MeterCharge
    {
        return $read instanceof MeterCharge ? $read : throw new MalformedResponseException('The verified answer is not a meter charge.');
    }

    private static function refusal(ProductCreateOutcome $outcome): \Anis\Partners\Errors\AnisApiException
    {
        return $outcome->refusal ?? throw new MalformedResponseException('The refusal is missing.');
    }
}
