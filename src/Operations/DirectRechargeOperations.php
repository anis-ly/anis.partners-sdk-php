<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\CreateDirectRechargeRequest;
use Anis\Partners\Models\DirectRecharge;
use Anis\Partners\Models\DirectRechargeAccepted;
use Anis\Partners\Models\DirectRechargeFinal;
use Anis\Partners\Models\DirectRechargeNotPlaced;
use Anis\Partners\Models\DirectRechargeOutcomeUnknown;
use Anis\Partners\Models\DirectRechargeProcessing;
use Anis\Partners\Models\DirectRechargeResult;
use Anis\Partners\Models\DirectRechargesQuery;
use Anis\Partners\Models\DirectRechargeStatus;
use Anis\Partners\Models\DirectRechargeSummary;
use Anis\Partners\Models\Page;
use Anis\Partners\Models\UpdateDirectRechargeRecipientRequest;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureProfile;
use Psr\Log\LoggerInterface;

/** Creates direct recharges under the caller's durable operation id, follows them and corrects paused recipient details. */
final class DirectRechargeOperations extends AbstractOperations
{
    private const CREATE_TEMPLATE = '/v1/wallets/{walletId}/direct-recharges';

    private readonly ProductCreateFlow $flow;

    /** @internal */
    public function __construct(private readonly PartnerTransport $transport, ?LoggerInterface $logger = null)
    {
        $this->flow = new ProductCreateFlow($transport, $logger);
    }

    /**
     * Reads one page of this application's direct recharges for the wallet, newest first, without recipient details.
     * @return Page<DirectRechargeSummary>
     */
    public function getDirectRechargesPage(string $walletId, ?DirectRechargesQuery $query = null): Page
    {
        $query ??= new DirectRechargesQuery();
        $productId = $query->productId === null ? null : Uuid::canonical($query->productId, 'product id');
        $path = $this->withQuery('v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/direct-recharges', [
            'status' => $query->status?->value,
            'productId' => $productId,
            'from' => self::utc($query->from),
            'to' => self::utc($query->to),
            'cursor' => $query->cursor,
            'pageSize' => self::pageSize($query->pageSize),
        ]);

        return $this->fetchPage($this->transport, self::CREATE_TEMPLATE, $path, [DirectRechargeSummary::class, 'fromArray'], true);
    }

    /**
     * Walks every direct recharge page; the cursor in the query only sets where the walk starts.
     * @return \Generator<int, DirectRechargeSummary>
     */
    public function getDirectRecharges(string $walletId, ?DirectRechargesQuery $query = null): \Generator
    {
        $query ??= new DirectRechargesQuery();

        return $this->walk(fn(?string $cursor): Page => $this->getDirectRechargesPage($walletId, new DirectRechargesQuery($query->status, $query->productId, $query->from, $query->to, $cursor, $query->pageSize)), $query->cursor);
    }

    /** Creates a direct recharge; the operation id must be created and stored with the exact request before this call. */
    public function create(string $walletId, string $operationId, CreateDirectRechargeRequest $request): DirectRechargeResult
    {
        return $this->send($walletId, $operationId, $request, false);
    }

    /** Repeats the same request under the same operation id with a fresh signature; it never creates an id. */
    public function resume(string $walletId, string $operationId, CreateDirectRechargeRequest $request): DirectRechargeResult
    {
        return $this->send($walletId, $operationId, $request, true);
    }

    /**
     * Reads the direct recharge this application owns.
     *
     * Before Anis has it in its read store it answers with the three-member processing answer instead of a read.
     * A recorded refusal of the create answers with the same typed {@see \Anis\Partners\Errors\AnisApiException} as the
     * create (status 409 or 422, `isReplayed` true) and is the definitive answer for this id; an `idempotency_conflict`
     * means no direct recharge of your application exists under it.
     */
    public function get(string $operationId): DirectRecharge|DirectRechargeAccepted
    {
        $id = Uuid::canonical($operationId, 'operation id');
        $response = $this->transport->request('GET', '/v1/direct-recharges/{operationId}', 'v1/direct-recharges/' . $id, SignatureProfile::SafeRead);
        try {
            $value = $response->status === 202 ? DirectRechargeAccepted::fromArray($response->json) : DirectRecharge::fromArray($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The verified direct recharge answer does not match the model.');
        }
        if ($value->operationId !== $id) {
            throw new MalformedResponseException('The verified direct recharge answer names another operation than the one asked for.');
        }

        return $value;
    }

    /**
     * Corrects the recipient details while the direct recharge is paused on wrong data.
     *
     * This is a signed change, not a create: it is sent once and never replayed for you. After a lost answer read the
     * direct recharge before deciding to send it again. Acceptance is a signed 202, not proof that the read has caught
     * up. The result is the same processing wrapper as a 202 create, with the verified Location and retry delay;
     * its read is null because this answer contains only the three-member acknowledgement.
     */
    public function updateRecipient(string $operationId, UpdateDirectRechargeRecipientRequest $request): DirectRechargeProcessing
    {
        $id = Uuid::canonical($operationId, 'operation id');
        try {
            $body = $request->toJson();
        } catch (\Anis\Partners\Errors\AnisPartnersInvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RequestSigningException($exception);
        }
        $response = $this->transport->request('PATCH', '/v1/direct-recharges/{operationId}/recipient', 'v1/direct-recharges/' . $id . '/recipient', SignatureProfile::BodylessNonceMutation, $body);
        return self::processing(ProductCreateFlow::accepted(
            $response,
            $id,
            '/v1/direct-recharges/' . $id,
            static fn(array $json): string => DirectRechargeAccepted::fromArray($json)->operationId,
            requireLocation: true,
        ));
    }

    private function send(string $walletId, string $operationId, CreateDirectRechargeRequest $request, bool $resuming): DirectRechargeResult
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
            'v1/wallets/' . $wallet . '/direct-recharges',
            $body,
            $operation,
            $resuming,
            static fn(array $json): string => DirectRechargeAccepted::fromArray($json)->operationId,
            [DirectRecharge::class, 'fromArray'],
            static function (object $read): array {
                if (!$read instanceof DirectRecharge) {
                    return ['', ProductCreateFlow::UNRESOLVED];
                }

                return [$read->operationId, match ($read->status) {
                    DirectRechargeStatus::Completed, DirectRechargeStatus::PaymentFailed, DirectRechargeStatus::RejectedWithRefund => ProductCreateFlow::FINAL,
                    DirectRechargeStatus::Unknown => ProductCreateFlow::UNRESOLVED,
                    default => ProductCreateFlow::OPEN,
                }];
            },
            '/v1/direct-recharges/' . $operation,
            'direct recharge',
        );

        return match ($outcome->kind) {
            'final' => new DirectRechargeFinal(self::recharge($outcome->read), $outcome->replayed),
            'open' => self::processing($outcome),
            'refused' => new DirectRechargeNotPlaced($operation, $outcome->refusal ?? throw new MalformedResponseException('The refusal is missing.')),
            default => new DirectRechargeOutcomeUnknown($operation, $outcome->delaySeconds, $outcome->cause ?? new MalformedResponseException('The outcome is unknown.')),
        };
    }

    private static function processing(ProductCreateOutcome $outcome): DirectRechargeProcessing
    {
        return new DirectRechargeProcessing($outcome->operationId, $outcome->delaySeconds, $outcome->location, $outcome->read instanceof DirectRecharge ? $outcome->read : null);
    }

    private static function recharge(?object $read): DirectRecharge
    {
        return $read instanceof DirectRecharge ? $read : throw new MalformedResponseException('The verified answer is not a direct recharge.');
    }
}
