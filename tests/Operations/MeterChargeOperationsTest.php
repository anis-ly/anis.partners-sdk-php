<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\AnisPartnersInvalidArgumentException;
use Anis\Partners\Errors\IdempotencyConflictException;
use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Models\CreateMeterChargeRequest;
use Anis\Partners\Models\MeterCharge;
use Anis\Partners\Models\MeterChargeAccepted;
use Anis\Partners\Models\MeterChargeFinal;
use Anis\Partners\Models\MeterChargeNotPlaced;
use Anis\Partners\Models\MeterChargeOutcomeUnknown;
use Anis\Partners\Models\MeterChargeProcessing;
use Anis\Partners\Models\MeterChargesQuery;
use Anis\Partners\Models\MeterChargeStatus;
use Anis\Partners\Models\MeterFailureCode;
use Anis\Partners\Models\MeterRefundStatus;
use Anis\Partners\Models\Money;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MeterChargeOperationsTest extends TestCase
{
    private const LOCATION = '/v1/meter-charges/' . P::METER_OPERATION;

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    private static function request(): CreateMeterChargeRequest
    {
        return new CreateMeterChargeRequest(P::PACKAGE, '0268000710262', Money::of('97.500', 'LYD'), 'POS-77-INV-1093');
    }

    /** @param array<string, string> $headers */
    private static function wire(int $status, string $body, array $headers = []): SignedFakeWire
    {
        $wire = new SignedFakeWire();
        $wire->status = $status;
        $wire->body = $body;
        $wire->responseHeaders = ['X-Request-Id' => 'req-meter'] + $headers;

        return $wire;
    }

    #[Test]
    public function it_reads_packages_without_fetching_signing_keys_and_omits_a_missing_business_price(): void
    {
        $wire = self::wire(200, P::json(['items' => [['id' => P::PACKAGE, 'name' => 'باقة', 'gecolPackageName' => 'PKG_100', 'logo' => 'https://cdn.example/1.png', 'unitPrice' => ['amount' => '97.500', 'currency' => 'LYD'], 'personalPrice' => ['amount' => '100.000', 'currency' => 'LYD']]], 'nextCursor' => null]));
        $wire->signResponses = false;
        $page = self::client($wire)->meterCharges()->getMeterPackagesPage(P::WALLET);

        self::assertCount(1, $page->items);
        self::assertNull($page->items[0]->businessPrice);
        self::assertSame('97.500', $page->items[0]->unitPrice->amount());
        self::assertCount(1, $wire->requests);
        self::assertSame('/v1/wallets/' . P::WALLET . '/meter-packages', $wire->requests[0]->getUri()->getPath());
    }

    #[Test]
    public function it_walks_package_pages_encoding_each_cursor_exactly_once(): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $item = ['id' => P::PACKAGE, 'name' => 'p', 'gecolPackageName' => 'G', 'logo' => 'l', 'unitPrice' => ['amount' => '1.000', 'currency' => 'LYD'], 'personalPrice' => ['amount' => '1.000', 'currency' => 'LYD']];
        $wire->responseQueue = [
            ['status' => 200, 'body' => P::json(['items' => [$item], 'nextCursor' => 'a+b/c=&d']), 'headers' => []],
            ['status' => 200, 'body' => P::json(['items' => [$item], 'nextCursor' => null]), 'headers' => []],
        ];
        $all = iterator_to_array(self::client($wire)->meterCharges()->getMeterPackages(P::WALLET), false);

        self::assertCount(2, $all);
        self::assertSame('cursor=a%2Bb%2Fc%3D%26d', $wire->requests[1]->getUri()->getQuery());
    }

    #[Test]
    public function it_refuses_a_repeated_package_cursor(): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->body = P::json(['items' => [], 'nextCursor' => 'same']);

        $this->expectException(MalformedResponseException::class);
        iterator_to_array(self::client($wire)->meterCharges()->getMeterPackages(P::WALLET), false);
    }

    #[Test]
    public function it_encodes_meter_charge_list_filters_once_in_utc(): void
    {
        $wire = self::wire(200, P::json(['items' => [], 'nextCursor' => null]));
        $wire->signResponses = false;
        $query = new MeterChargesQuery(MeterChargeStatus::Succeeded, '0268000710262', new \DateTimeImmutable('2026-10-01T02:00:00+02:00'), new \DateTimeImmutable('2026-10-20T12:00:00Z'), 'x+y', 50);
        $page = self::client($wire)->meterCharges()->getMeterChargesPage(P::WALLET, $query);

        self::assertSame([], $page->items);
        self::assertSame('status=succeeded&meterNumber=0268000710262&from=2026-10-01T00%3A00%3A00Z&to=2026-10-20T12%3A00%3A00Z&cursor=x%2By&pageSize=50', $wire->requests[0]->getUri()->getQuery());
    }

    #[Test]
    public function it_reads_a_meter_charge_summary_page_without_receipt(): void
    {
        $wire = self::wire(200, P::json(['items' => [['operationId' => P::METER_OPERATION, 'status' => 'succeeded', 'meterNumber' => '0268000710262', 'package' => ['id' => P::PACKAGE, 'name' => 'p', 'gecolPackageName' => 'G'], 'total' => ['amount' => '97.500', 'currency' => 'LYD'], 'createdAt' => '2026-10-20T08:12:44Z', 'completedAt' => '2026-10-20T08:13:02Z']], 'nextCursor' => null]));
        $wire->signResponses = false;
        $items = iterator_to_array(self::client($wire)->meterCharges()->getMeterCharges(P::WALLET), false);

        self::assertSame(MeterChargeStatus::Succeeded, $items[0]->status);
        self::assertNull($items[0]->externalReference);
    }

    #[Test]
    public function it_refuses_an_out_of_range_page_size_before_sending(): void
    {
        $wire = new SignedFakeWire();
        $this->expectException(AnisPartnersInvalidArgumentException::class);
        try {
            self::client($wire)->meterCharges()->getMeterChargesPage(P::WALLET, new MeterChargesQuery(pageSize: 101));
        } finally {
            self::assertSame([], $wire->requests);
        }
    }

    #[Test]
    public function it_signs_exactly_the_bytes_it_sends_with_the_caller_operation_id_as_the_key(): void
    {
        $wire = self::wire(202, P::accepted('meterCharge', P::METER_OPERATION), ['Location' => self::LOCATION, 'Retry-After' => '7']);
        $client = self::client($wire);
        $first = $client->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        $second = $client->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());

        $sent = $wire->requests[0];
        $body = (string) $sent->getBody();
        self::assertSame('{"packageId":"' . P::PACKAGE . '","meterNumber":"0268000710262","expectedTotal":{"amount":"97.500","currency":"LYD"},"externalReference":"POS-77-INV-1093"}', $body);
        self::assertStringNotContainsString('operationId', $body);
        self::assertSame(P::METER_OPERATION, $sent->getHeaderLine('Idempotency-Key'));
        self::assertSame(ContentDigest::of($body), $sent->getHeaderLine('Content-Digest'));
        self::assertStringContainsString('"idempotency-key"', $sent->getHeaderLine('Signature-Input'));
        self::assertInstanceOf(MeterChargeProcessing::class, $first);
        self::assertInstanceOf(MeterChargeProcessing::class, $second);
        self::assertSame(7, $first->retryAfterSeconds);
        self::assertSame(self::LOCATION, $first->location);
        $resumed = array_values(array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'POST'));
        self::assertCount(2, $resumed);
        self::assertSame($body, (string) $resumed[1]->getBody());
        self::assertNotSame($resumed[0]->getHeaderLine('Nonce'), $resumed[1]->getHeaderLine('Nonce'));
    }

    #[Test]
    public function it_does_not_pass_on_a_location_it_did_not_expect(): void
    {
        $wire = self::wire(202, P::accepted('meterCharge', P::METER_OPERATION), ['Location' => 'https://elsewhere.example/x']);
        $result = self::client($wire)->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeProcessing::class, $result);
        self::assertNull($result->location);
        self::assertSame(5, $result->retryAfterSeconds);
    }

    /** @return iterable<string, array{string}> */
    public static function badAcceptedBodies(): iterable
    {
        yield 'extra member' => [P::json(['operationId' => P::METER_OPERATION, 'operationType' => 'meterCharge', 'status' => 'processing', 'walletId' => P::WALLET])];
        yield 'wrong operation type' => [P::accepted('directRecharge', P::METER_OPERATION)];
        yield 'missing type' => [P::json(['operationId' => P::METER_OPERATION, 'status' => 'processing'])];
        yield 'another operation' => [P::accepted('meterCharge', P::RECHARGE_OPERATION)];
    }

    #[Test]
    #[DataProvider('badAcceptedBodies')]
    public function it_treats_a_malformed_processing_answer_as_unknown_under_the_original_id(string $body): void
    {
        $result = self::client(self::wire(202, $body))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $result);
        self::assertSame(P::METER_OPERATION, $result->operationId);
    }

    #[Test]
    public function it_returns_a_succeeded_charge_with_every_token_in_order(): void
    {
        $result = self::client(self::wire(200, P::json(P::meterCharge('succeeded'))))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeFinal::class, $result);
        $receipt = $result->charge->receipt;
        self::assertNotNull($receipt);
        self::assertSame([1, 2, 3], array_map(static fn($token): int => $token->sequence, $receipt->tokens));
        self::assertSame('5821 0034 7719 2265 0418', $receipt->tokens[2]->code);
        self::assertNull($receipt->tokens[1]->description);
        self::assertSame('88.640', $receipt->creditedAmount);
        self::assertSame('3.860', $receipt->taxes[1]->amount);
        self::assertSame('100.000', $result->charge->personalPrice->amount());
        self::assertNotNull($result->charge->completedAt);
    }

    #[Test]
    public function it_keeps_a_failed_charge_with_a_refund_as_a_final_result(): void
    {
        $result = self::client(self::wire(200, P::json(P::meterCharge('failed'))))->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeFinal::class, $result);
        self::assertNotNull($result->charge->failure);
        self::assertSame(MeterFailureCode::MeterDebtExceedsPackage, $result->charge->failure->code);
        self::assertSame(MeterRefundStatus::Refunded, $result->charge->refund?->status);
        self::assertNull($result->charge->completedAt);
    }

    /** @return iterable<string, array{string}> */
    public static function refundStates(): iterable
    {
        yield 'not charged' => ['notCharged'];
    }

    #[Test]
    #[DataProvider('refundStates')]
    public function it_reads_each_refund_state_with_an_absent_amount(string $state): void
    {
        $data = P::meterCharge('failed');
        $data['refund'] = ['status' => $state];
        $charge = MeterCharge::fromArray($data);

        self::assertNull($charge->refund?->amount);
        self::assertSame($state, $charge->refund?->status->value);
    }

    #[Test]
    public function it_reads_every_failure_code_and_keeps_a_future_one_raw(): void
    {
        foreach (MeterFailureCode::cases() as $code) {
            if ($code === MeterFailureCode::Unknown) {
                continue;
            }
            $data = P::meterCharge('failed');
            $data['failure'] = ['code' => $code->value];
            self::assertSame($code, MeterCharge::fromArray($data)->failure?->code);
        }
        $data = P::meterCharge('failed');
        $data['failure'] = ['code' => 'gecol_new_reason'];
        $failure = MeterCharge::fromArray($data)->failure;
        self::assertSame(MeterFailureCode::Unknown, $failure?->code);
        self::assertSame('gecol_new_reason', $failure->rawCode);
        self::assertSame(14, count(MeterFailureCode::cases()) - 1);
    }

    #[Test]
    public function it_keeps_a_processing_read_open_and_an_unknown_status_unresolved(): void
    {
        $open = self::client(self::wire(200, P::json(P::meterCharge('processing'))))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeProcessing::class, $open);
        self::assertSame(MeterChargeStatus::Processing, $open->read?->status);

        $unknown = self::client(self::wire(200, P::json(P::meterCharge('someday'))))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $unknown);
    }

    #[Test]
    public function it_closes_a_definitive_refusal_of_the_first_create_but_not_a_fresh_one_on_resume(): void
    {
        $body = '{"status":422,"code":"meter_rejected"}';
        $created = self::client(self::wire(422, $body))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeNotPlaced::class, $created);

        $resumed = self::client(self::wire(422, $body))->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $resumed);

        $replayed = self::client(self::wire(422, $body, ['Idempotency-Replayed' => 'true']))->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeNotPlaced::class, $replayed);
    }

    #[Test]
    public function it_keeps_a_foreign_id_idempotency_conflict_on_resume_unknown_without_a_new_purchase(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 202, 'body' => P::accepted('meterCharge', P::METER_OPERATION), 'headers' => ['X-Request-Id' => 'r1', 'Location' => self::LOCATION]],
            ['status' => 409, 'body' => '{"status":409,"code":"idempotency_conflict"}', 'headers' => ['X-Request-Id' => 'r2']],
        ];
        $client = self::client($wire);
        $before = $client->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        $after = $client->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeProcessing::class, $before);
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $after);
        self::assertInstanceOf(IdempotencyConflictException::class, $after->cause);
        self::assertSame(P::METER_OPERATION, $after->operationId);
        $posts = array_values(array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'POST'));
        self::assertCount(2, $posts);
        self::assertSame((string) $posts[0]->getBody(), (string) $posts[1]->getBody());
        self::assertSame(P::METER_OPERATION, $posts[1]->getHeaderLine('Idempotency-Key'));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function conflictCases(): iterable
    {
        yield 'create' => [false, false];
        yield 'resume' => [true, false];
        yield 'create replayed' => [false, true];
        yield 'resume replayed' => [true, true];
    }

    #[Test]
    #[DataProvider('conflictCases')]
    public function it_never_calls_an_idempotency_conflict_not_placed(bool $resuming, bool $replayed): void
    {
        $wire = self::wire(409, '{"status":409,"code":"idempotency_conflict"}', $replayed ? ['Idempotency-Replayed' => 'true'] : []);
        $meter = self::client($wire)->meterCharges();
        $result = $resuming ? $meter->resume(P::WALLET, P::METER_OPERATION, self::request()) : $meter->create(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $result);
        self::assertSame(P::METER_OPERATION, $result->operationId);
        self::assertInstanceOf(IdempotencyConflictException::class, $result->cause);
        self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'POST'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function recordedRefusals(): iterable
    {
        yield '422 meter_rejected' => [422, 'meter_rejected'];
        yield '422 amount_out_of_range' => [422, 'amount_out_of_range'];
        yield '409 insufficient_balance' => [409, 'insufficient_balance'];
        yield '409 price_changed' => [409, 'price_changed'];
        yield '409 idempotency_conflict' => [409, 'idempotency_conflict'];
    }

    #[Test]
    #[DataProvider('recordedRefusals')]
    public function it_raises_the_typed_refusal_when_a_get_answers_the_recorded_refusal_of_the_create(int $status, string $code): void
    {
        $wire = self::wire($status, '{"status":' . $status . ',"code":"' . $code . '"}', ['Idempotency-Replayed' => 'true']);
        try {
            self::client($wire)->meterCharges()->get(P::METER_OPERATION);
            self::fail('A recorded refusal must be raised as the typed API error.');
        } catch (\Anis\Partners\Errors\AnisApiException $refusal) {
            self::assertSame($status, $refusal->status);
            self::assertSame($code, $refusal->errorCode->value);
            self::assertTrue($refusal->isReplayed);
        }
        $reads = array_values(array_filter($wire->requests, static fn($request): bool => $request->getUri()->getPath() === '/v1/meter-charges/' . P::METER_OPERATION));
        self::assertCount(1, $reads);
        self::assertSame('GET', $reads[0]->getMethod());
    }

    #[Test]
    public function it_keeps_a_failed_charge_with_a_pending_refund_open_until_the_refund_lands(): void
    {
        $pending = P::meterCharge('failed', ['refund' => ['status' => 'pending', 'amount' => ['amount' => '97.500', 'currency' => 'LYD']]]);

        foreach ([false, true] as $resuming) {
            $meter = self::client(self::wire(200, P::json($pending)))->meterCharges();
            $result = $resuming ? $meter->resume(P::WALLET, P::METER_OPERATION, self::request()) : $meter->create(P::WALLET, P::METER_OPERATION, self::request());

            self::assertInstanceOf(MeterChargeProcessing::class, $result);
            self::assertSame(MeterChargeStatus::Failed, $result->read?->status);
            self::assertSame(MeterRefundStatus::Pending, $result->read->refund?->status);
            self::assertSame('97.500', $result->read->refund->amount?->amount());
        }

        $read = self::client(self::wire(200, P::json($pending)))->meterCharges()->get(P::METER_OPERATION);
        self::assertInstanceOf(MeterCharge::class, $read);
        self::assertSame(MeterRefundStatus::Pending, $read->refund?->status);
        self::assertSame('97.500', $read->refund->amount?->amount());
    }

    #[Test]
    public function it_closes_a_failed_charge_once_the_refund_is_refunded_or_not_charged(): void
    {
        foreach (['refunded' => ['status' => 'refunded', 'amount' => ['amount' => '97.500', 'currency' => 'LYD']], 'notCharged' => ['status' => 'notCharged']] as $state => $refund) {
            $result = self::client(self::wire(200, P::json(P::meterCharge('failed', ['refund' => $refund]))))->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());

            self::assertInstanceOf(MeterChargeFinal::class, $result, $state);
            self::assertSame($state, $result->charge->refund?->status->value);
        }
    }

    #[Test]
    public function it_keeps_a_lost_unsigned_tampered_or_unreadable_answer_unknown(): void
    {
        $lost = new SignedFakeWire();
        $lost->failure = new \RuntimeException('connection dropped');
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, self::client($lost)->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request()));

        $unsigned = self::wire(202, P::accepted('meterCharge', P::METER_OPERATION));
        $unsigned->signResponses = false;
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, self::client($unsigned)->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request()));

        $tampered = self::wire(202, P::accepted('meterCharge', P::METER_OPERATION));
        $tampered->tamperAfterSigning = true;
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, self::client($tampered)->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request()));

        foreach (['{', '', 'null'] as $body) {
            $result = self::client(self::wire(200, $body))->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());
            self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $result);
        }
    }

    #[Test]
    public function it_keeps_access_refusals_on_resume_unknown(): void
    {
        $result = self::client(self::wire(403, '{"status":403,"code":"insufficient_scope"}'))->meterCharges()->resume(P::WALLET, P::METER_OPERATION, self::request());

        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $result);
        self::assertSame(60, $result->suggestedDelaySeconds);
    }

    #[Test]
    public function it_rethrows_a_signer_failure_before_anything_is_sent(): void
    {
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $signer = new class implements \Anis\Partners\Signing\RequestSigner {
            public function sign(string $data): string
            {
                throw new \RuntimeException('signer unavailable');
            }
            public function keyId(): string
            {
                return '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';
            }
        };
        $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $signer, $wire, $factory, $factory);

        $this->expectException(RequestSigningException::class);
        try {
            $client->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        } finally {
            self::assertSame([], $wire->requests);
        }
    }

    #[Test]
    public function it_refuses_an_invalid_request_before_sending(): void
    {
        $this->expectException(AnisPartnersInvalidArgumentException::class);
        new CreateMeterChargeRequest(P::PACKAGE, '0268000710262', Money::of('0.000', 'LYD'));
    }

    #[Test]
    public function it_reads_a_signed_charge_and_the_pre_projection_processing_answer(): void
    {
        $wire = self::wire(200, P::json(P::meterCharge('succeeded')));
        $read = self::client($wire)->meterCharges()->get(P::METER_OPERATION);
        self::assertInstanceOf(MeterCharge::class, $read);
        self::assertSame('/v1/meter-charges/' . P::METER_OPERATION, $wire->requests[0]->getUri()->getPath());

        $early = self::client(self::wire(202, P::accepted('meterCharge', P::METER_OPERATION)))->meterCharges()->get(P::METER_OPERATION);
        self::assertInstanceOf(MeterChargeAccepted::class, $early);
        self::assertSame(['operationId' => P::METER_OPERATION, 'operationType' => 'meterCharge', 'status' => 'processing'], $early->jsonSerialize());
    }

    #[Test]
    public function it_discards_an_unsigned_charge_read_and_another_operations_answer(): void
    {
        $unsigned = self::wire(200, P::json(P::meterCharge('succeeded')));
        $unsigned->signResponses = false;
        try {
            self::client($unsigned)->meterCharges()->get(P::METER_OPERATION);
            self::fail('An unsigned read of a signed route must be discarded.');
        } catch (\Anis\Partners\Verification\UnverifiableResponseException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(MalformedResponseException::class);
        self::client(self::wire(200, P::json(P::meterCharge('succeeded', ['operationId' => P::RECHARGE_OPERATION]))))->meterCharges()->get(P::METER_OPERATION);
    }

    #[Test]
    public function it_never_writes_tokens_or_recipient_data_to_the_log(): void
    {
        $wire = self::wire(200, P::json(P::meterCharge('succeeded')));
        $logger = new \Anis\Partners\Tests\Support\RecordingLogger();
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, null, $logger);

        $client->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        $client->meterCharges()->get(P::METER_OPERATION);

        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertNotSame([], $logger->records);
        foreach (['5821 0034 7719 2265 0418', '1234 5678 9012 3456 7890', '260020103744'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function chargedRefundStates(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'refunded' => ['refunded'];
    }

    #[Test]
    #[DataProvider('chargedRefundStates')]
    public function it_requires_the_amount_when_a_charge_is_pending_refund_or_refunded(string $state): void
    {
        $data = P::meterCharge('failed', ['refund' => ['status' => $state]]);
        $result = self::client(self::wire(200, P::json($data)))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $result);
        $this->expectException(\Anis\Partners\Errors\AnisPartnersUnexpectedValueException::class);
        MeterCharge::fromArray($data);
    }

    #[Test]
    public function it_does_not_return_a_final_charge_with_missing_required_identities(): void
    {
        $data = P::meterCharge();
        self::assertIsArray($data['package']);
        unset($data['walletId'], $data['package']['id']);
        $result = self::client(self::wire(200, P::json($data)))->meterCharges()->create(P::WALLET, P::METER_OPERATION, self::request());
        self::assertInstanceOf(MeterChargeOutcomeUnknown::class, $result);
    }
}
