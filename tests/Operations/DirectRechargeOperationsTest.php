<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\AnisPartnersInvalidArgumentException;
use Anis\Partners\Errors\IdempotencyConflictException;
use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Errors\ValidationFailedException;
use Anis\Partners\Models\CreateDirectRechargeRequest;
use Anis\Partners\Models\DirectRecharge;
use Anis\Partners\Models\DirectRechargeAccepted;
use Anis\Partners\Models\DirectRechargeFinal;
use Anis\Partners\Models\DirectRechargeNotPlaced;
use Anis\Partners\Models\DirectRechargeOutcomeUnknown;
use Anis\Partners\Models\DirectRechargeProcessing;
use Anis\Partners\Models\DirectRechargeRecipientInput;
use Anis\Partners\Models\DirectRechargesQuery;
use Anis\Partners\Models\DirectRechargeStatus;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\UpdateDirectRechargeRecipientRequest;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectRechargeOperationsTest extends TestCase
{
    private const LOCATION = '/v1/direct-recharges/' . P::RECHARGE_OPERATION;

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    private static function request(): CreateDirectRechargeRequest
    {
        return new CreateDirectRechargeRequest(
            P::PRODUCT,
            '1500.000',
            Money::of('237.500', 'LYD'),
            [new DirectRechargeRecipientInput('recipient_number', '01012345678'), new DirectRechargeRecipientInput('recipient_name', 'أحمد علي')],
            'Family support',
            'POS-77-TR-551',
        );
    }

    /** @param array<string, string> $headers */
    private static function wire(int $status, string $body, array $headers = []): SignedFakeWire
    {
        $wire = new SignedFakeWire();
        $wire->status = $status;
        $wire->body = $body;
        $wire->responseHeaders = ['X-Request-Id' => 'req-dr'] + $headers;

        return $wire;
    }

    #[Test]
    public function it_signs_exactly_the_bytes_it_sends_with_recipient_values_and_no_price_members(): void
    {
        $wire = self::wire(202, P::accepted('directRecharge', P::RECHARGE_OPERATION), ['Location' => self::LOCATION, 'Retry-After' => '9']);
        $result = self::client($wire)->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());

        $sent = $wire->requests[0];
        $body = (string) $sent->getBody();
        self::assertSame('{"productId":"' . P::PRODUCT . '","payoutAmount":"1500.000","expectedTotal":{"amount":"237.500","currency":"LYD"},"recipient":[{"key":"recipient_number","value":"01012345678"},{"key":"recipient_name","value":"أحمد علي"}],"note":"Family support","externalReference":"POS-77-TR-551"}', $body);
        foreach (['price', 'priceWithCommission', 'extraFee', 'operationId', 'customerWhatsappNumber'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
        self::assertSame(P::RECHARGE_OPERATION, $sent->getHeaderLine('Idempotency-Key'));
        self::assertSame(ContentDigest::of($body), $sent->getHeaderLine('Content-Digest'));
        self::assertInstanceOf(DirectRechargeProcessing::class, $result);
        self::assertSame(9, $result->retryAfterSeconds);
        self::assertSame(self::LOCATION, $result->location);
    }

    /** @return iterable<string, array{string}> */
    public static function badAcceptedBodies(): iterable
    {
        yield 'extra member' => [P::json(['operationId' => P::RECHARGE_OPERATION, 'operationType' => 'directRecharge', 'status' => 'processing', 'number' => 1])];
        yield 'meter type' => [P::accepted('meterCharge', P::RECHARGE_OPERATION)];
        yield 'another operation' => [P::accepted('directRecharge', P::METER_OPERATION)];
    }

    #[Test]
    #[DataProvider('badAcceptedBodies')]
    public function it_treats_a_malformed_processing_answer_as_unknown(string $body): void
    {
        $result = self::client(self::wire(202, $body))->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());

        self::assertInstanceOf(DirectRechargeOutcomeUnknown::class, $result);
        self::assertSame(P::RECHARGE_OPERATION, $result->operationId);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function states(): iterable
    {
        yield 'completed' => ['completed', true];
        yield 'paymentFailed' => ['paymentFailed', true];
        yield 'rejectedWithRefund' => ['rejectedWithRefund', true];
        yield 'awaitingPayment' => ['awaitingPayment', false];
        yield 'pending' => ['pending', false];
        yield 'inProgress' => ['inProgress', false];
        yield 'suspendedDataError' => ['suspendedDataError', false];
        yield 'rejectedAwaitingRefund' => ['rejectedAwaitingRefund', false];
    }

    #[Test]
    #[DataProvider('states')]
    public function it_closes_recovery_only_on_authoritative_final_states(string $status, bool $final): void
    {
        $result = self::client(self::wire(200, P::json(P::directRecharge($status))))->directRecharges()->resume(P::WALLET, P::RECHARGE_OPERATION, self::request());

        self::assertInstanceOf($final ? DirectRechargeFinal::class : DirectRechargeProcessing::class, $result);
    }

    #[Test]
    public function it_keeps_an_unknown_status_unresolved_and_a_partial_refund_alone_not_completed(): void
    {
        $unknown = self::client(self::wire(200, P::json(P::directRecharge('brandNew'))))->directRecharges()->resume(P::WALLET, P::RECHARGE_OPERATION, self::request());
        self::assertInstanceOf(DirectRechargeOutcomeUnknown::class, $unknown);

        $partial = P::directRecharge('inProgress', ['partialRefund' => ['refundedPayout' => '500.000', 'refundedTotal' => ['amount' => '77.500', 'currency' => 'LYD'], 'remainingPayout' => '1000.000', 'remainingTotal' => ['amount' => '160.000', 'currency' => 'LYD']]]);
        $result = self::client(self::wire(200, P::json($partial)))->directRecharges()->resume(P::WALLET, P::RECHARGE_OPERATION, self::request());
        self::assertInstanceOf(DirectRechargeProcessing::class, $result);
        self::assertSame('1000.000', $result->read?->partialRefund?->remainingPayout);
        self::assertSame($partial['partialRefund'], $result->read->partialRefund->jsonSerialize());
    }

    #[Test]
    public function it_reads_every_status_and_optional_members_absent(): void
    {
        foreach (DirectRechargeStatus::cases() as $case) {
            if ($case === DirectRechargeStatus::Unknown) {
                continue;
            }
            self::assertSame($case, DirectRecharge::fromArray(P::directRecharge($case->value))->status);
        }
        $read = DirectRecharge::fromArray(P::directRecharge('pending'));
        self::assertNull($read->proofUrl);
        self::assertNull($read->reason);
        self::assertNull($read->completedAt);
        self::assertNull($read->partialRefund);
        self::assertSame('القاهرة', $read->recipient[1]->valueName);
        self::assertSame('1500.000', $read->payout->amount);
        $suspended = DirectRecharge::fromArray(P::directRecharge('suspendedDataError'));
        self::assertSame('رقم المحفظة غير صحيح', $suspended->reason);
    }

    #[Test]
    public function it_maps_definitive_refusals_and_keeps_recipient_field_errors(): void
    {
        foreach (['direct_recharge_product_unavailable' => 409, 'amount_out_of_range' => 422] as $code => $status) {
            $result = self::client(self::wire($status, json_encode(['status' => $status, 'code' => $code], JSON_THROW_ON_ERROR)))->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());
            self::assertInstanceOf(DirectRechargeNotPlaced::class, $result);
            self::assertSame($code, $result->refusal->rawCode);
        }
        $body = '{"status":422,"code":"validation_failed","extensions":{"fields":{"recipient_number":"رقم غير صحيح"}}}';
        $invalid = self::client(self::wire(422, $body))->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());
        self::assertInstanceOf(DirectRechargeNotPlaced::class, $invalid);
        self::assertInstanceOf(ValidationFailedException::class, $invalid->refusal);
        self::assertSame(['recipient_number' => 'رقم غير صحيح'], $invalid->refusal->fieldErrors());
        self::assertInstanceOf(\stdClass::class, $invalid->refusal->problem->extensions);
    }

    #[Test]
    public function it_keeps_a_foreign_id_idempotency_conflict_on_resume_unknown_without_a_new_purchase(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 202, 'body' => P::accepted('directRecharge', P::RECHARGE_OPERATION), 'headers' => ['X-Request-Id' => 'r1']],
            ['status' => 409, 'body' => '{"status":409,"code":"idempotency_conflict"}', 'headers' => ['X-Request-Id' => 'r2']],
        ];
        $client = self::client($wire);
        $before = $client->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());
        $after = $client->directRecharges()->resume(P::WALLET, P::RECHARGE_OPERATION, self::request());

        self::assertInstanceOf(DirectRechargeProcessing::class, $before);
        self::assertInstanceOf(DirectRechargeOutcomeUnknown::class, $after);
        self::assertInstanceOf(IdempotencyConflictException::class, $after->cause);
        $posts = array_values(array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'POST'));
        self::assertSame((string) $posts[0]->getBody(), (string) $posts[1]->getBody());
        self::assertSame(P::RECHARGE_OPERATION, $posts[1]->getHeaderLine('Idempotency-Key'));
        self::assertNotSame($posts[0]->getHeaderLine('Nonce'), $posts[1]->getHeaderLine('Nonce'));
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
        $recharges = self::client($wire)->directRecharges();
        $result = $resuming ? $recharges->resume(P::WALLET, P::RECHARGE_OPERATION, self::request()) : $recharges->create(P::WALLET, P::RECHARGE_OPERATION, self::request());

        self::assertInstanceOf(DirectRechargeOutcomeUnknown::class, $result);
        self::assertSame(P::RECHARGE_OPERATION, $result->operationId);
        self::assertInstanceOf(IdempotencyConflictException::class, $result->cause);
        self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'POST'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function recordedRefusals(): iterable
    {
        yield '422 amount_out_of_range' => [422, 'amount_out_of_range'];
        yield '422 validation_failed' => [422, 'validation_failed'];
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
            self::client($wire)->directRecharges()->get(P::RECHARGE_OPERATION);
            self::fail('A recorded refusal must be raised as the typed API error.');
        } catch (\Anis\Partners\Errors\AnisApiException $refusal) {
            self::assertSame($status, $refusal->status);
            self::assertSame($code, $refusal->errorCode->value);
            self::assertTrue($refusal->isReplayed);
        }
        $reads = array_values(array_filter($wire->requests, static fn($request): bool => $request->getUri()->getPath() === '/v1/direct-recharges/' . P::RECHARGE_OPERATION));
        self::assertCount(1, $reads);
        self::assertSame('GET', $reads[0]->getMethod());
    }

    #[Test]
    public function it_keeps_a_fresh_refusal_on_resume_and_a_lost_answer_unknown(): void
    {
        $resumed = self::client(self::wire(409, '{"status":409,"code":"price_changed"}'))->directRecharges()->resume(P::WALLET, P::RECHARGE_OPERATION, self::request());
        self::assertInstanceOf(DirectRechargeOutcomeUnknown::class, $resumed);

        $lost = new SignedFakeWire();
        $lost->failure = new \RuntimeException('connection dropped');
        $created = self::client($lost)->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());
        self::assertInstanceOf(DirectRechargeOutcomeUnknown::class, $created);
        self::assertSame(P::RECHARGE_OPERATION, $created->operationId);
    }

    #[Test]
    public function it_refuses_an_invalid_request_before_sending(): void
    {
        $this->expectException(AnisPartnersInvalidArgumentException::class);
        new CreateDirectRechargeRequest(P::PRODUCT, '1500', Money::of('237.500', 'LYD'), []);
    }

    #[Test]
    public function it_lists_direct_recharges_with_filters_and_summaries(): void
    {
        $wire = self::wire(200, P::json(['items' => [['operationId' => P::RECHARGE_OPERATION, 'status' => 'completed', 'number' => 30412, 'product' => ['id' => P::PRODUCT, 'title' => 'x'], 'payout' => ['amount' => '1500.000', 'currency' => ['name' => 'c']], 'total' => ['amount' => '237.500', 'currency' => 'LYD'], 'requestedAt' => '2026-10-20T09:00:00Z', 'hasPartialRefund' => false]], 'nextCursor' => null]));
        $wire->signResponses = false;
        $query = new DirectRechargesQuery(DirectRechargeStatus::Completed, P::PRODUCT, new \DateTimeImmutable('2026-10-01T00:00:00Z'), null, null, 50);
        $items = iterator_to_array(self::client($wire)->directRecharges()->getDirectRecharges(P::WALLET, $query), false);

        self::assertSame('status=completed&productId=' . P::PRODUCT . '&from=2026-10-01T00%3A00%3A00Z&pageSize=50', $wire->requests[0]->getUri()->getQuery());
        self::assertFalse($items[0]->hasPartialRefund);
        self::assertNull($items[0]->completedAt);
        self::assertCount(1, $wire->requests);
    }

    #[Test]
    public function it_sends_a_recipient_update_as_a_signed_change_without_an_idempotency_key(): void
    {
        $wire = self::wire(202, P::accepted('directRecharge', P::RECHARGE_OPERATION), ['Location' => self::LOCATION]);
        $request = new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]);
        $read = self::client($wire)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, $request);

        $sent = $wire->requests[0];
        $body = (string) $sent->getBody();
        self::assertSame('PATCH', $sent->getMethod());
        self::assertSame('/v1/direct-recharges/' . P::RECHARGE_OPERATION . '/recipient', $sent->getUri()->getPath());
        self::assertSame('{"recipient":[{"key":"recipient_number","value":"01098765432"}]}', $body);
        self::assertSame('', $sent->getHeaderLine('Idempotency-Key'));
        self::assertSame(ContentDigest::of($body), $sent->getHeaderLine('Content-Digest'));
        self::assertNotSame('', $sent->getHeaderLine('Nonce'));
        self::assertInstanceOf(DirectRechargeProcessing::class, $read);
        self::assertSame(P::RECHARGE_OPERATION, $read->operationId);
        self::assertSame(self::LOCATION, $read->location);
        self::assertSame(5, $read->retryAfterSeconds);
        self::assertNull($read->read);
        self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        self::assertSame([], array_values(array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'GET' && $request->getUri()->getPath() === self::LOCATION)));
    }

    #[Test]
    public function it_does_not_replay_a_recipient_update_after_a_lost_answer_or_a_refusal(): void
    {
        $request = new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]);
        $lost = new SignedFakeWire();
        $lost->failure = new \RuntimeException('connection dropped');
        try {
            self::client($lost)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, $request);
            self::fail('A lost answer must surface.');
        } catch (\RuntimeException) {
            self::assertCount(1, array_filter($lost->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        }

        $refused = self::wire(409, '{"status":409,"code":"direct_recharge_not_suspended"}');
        try {
            self::client($refused)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, $request);
            self::fail('A refusal must surface as an exception.');
        } catch (\Anis\Partners\Errors\AnisApiException $error) {
            self::assertSame('direct_recharge_not_suspended', $error->rawCode);
            self::assertCount(1, array_filter($refused->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        }
    }

    #[Test]
    public function it_reads_a_signed_direct_recharge_and_the_pre_projection_processing_answer(): void
    {
        $read = self::client(self::wire(200, P::json(P::directRecharge('completed'))))->directRecharges()->get(P::RECHARGE_OPERATION);
        self::assertInstanceOf(DirectRecharge::class, $read);

        $early = self::client(self::wire(202, P::accepted('directRecharge', P::RECHARGE_OPERATION)))->directRecharges()->get(P::RECHARGE_OPERATION);
        self::assertInstanceOf(DirectRechargeAccepted::class, $early);

        $this->expectException(MalformedResponseException::class);
        self::client(self::wire(200, P::json(P::directRecharge('completed', ['operationId' => P::METER_OPERATION]))))->directRecharges()->get(P::RECHARGE_OPERATION);
    }

    /** @return iterable<string, array{int, string, array<string, string|list<string>>}> */
    public static function invalidRecipientAcknowledgements(): iterable
    {
        $accepted = P::accepted('directRecharge', P::RECHARGE_OPERATION);
        yield 'missing Location' => [202, $accepted, []];
        yield 'empty Location' => [202, $accepted, ['Location' => '']];
        yield 'another operation Location' => [202, $accepted, ['Location' => '/v1/direct-recharges/' . P::METER_OPERATION]];
        yield 'foreign Location' => [202, $accepted, ['Location' => 'https://evil.example' . self::LOCATION]];
        yield 'duplicate Location' => [202, $accepted, ['Location' => [self::LOCATION, self::LOCATION]]];
        foreach (self::badAcceptedBodies() as $name => [$body]) {
            yield $name => [202, $body, ['Location' => self::LOCATION]];
        }
        yield 'Location must not be a body member' => [202, P::json(['operationId' => P::RECHARGE_OPERATION, 'operationType' => 'directRecharge', 'status' => 'processing', 'location' => self::LOCATION]), ['Location' => self::LOCATION]];
        yield 'missing member' => [202, P::json(['operationId' => P::RECHARGE_OPERATION, 'operationType' => 'directRecharge']), ['Location' => self::LOCATION]];
        yield 'wrong status' => [202, P::json(['operationId' => P::RECHARGE_OPERATION, 'operationType' => 'directRecharge', 'status' => 'inProgress']), ['Location' => self::LOCATION]];
        yield 'invalid UUID' => [202, P::accepted('directRecharge', 'invalid'), ['Location' => self::LOCATION]];
        yield 'invalid JSON' => [202, '{', ['Location' => self::LOCATION]];
        yield 'full exchange body' => [202, P::json(P::directRecharge('inProgress')), ['Location' => self::LOCATION]];
        yield '200 full read' => [200, P::json(P::directRecharge('inProgress')), ['Location' => self::LOCATION]];
        yield '200 acknowledgement' => [200, $accepted, ['Location' => self::LOCATION]];
        yield '201 acknowledgement' => [201, $accepted, ['Location' => self::LOCATION]];
    }

    /** @param array<string, string|list<string>> $headers */
    #[Test]
    #[DataProvider('invalidRecipientAcknowledgements')]
    public function it_refuses_invalid_recipient_acknowledgements_without_replaying_or_reading(int $status, string $body, array $headers): void
    {
        $wire = new SignedFakeWire();
        $wire->status = $status;
        $wire->body = $body;
        $wire->responseHeaders = ['X-Request-Id' => 'recipient-ack'] + $headers;
        try {
            self::client($wire)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]));
            self::fail('An invalid acknowledgement must surface as a malformed signed-change answer.');
        } catch (MalformedResponseException) {
            self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
            self::assertSame([], array_values(array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'GET' && $request->getUri()->getPath() === self::LOCATION)));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function unverifiedRecipientAnswers(): iterable
    {
        yield 'unsigned' => [false];
        yield 'tampered after signing' => [true];
    }

    #[Test]
    #[DataProvider('unverifiedRecipientAnswers')]
    public function it_verifies_the_recipient_acknowledgement_before_returning_it(bool $tampered): void
    {
        $wire = self::wire(202, P::accepted('directRecharge', P::RECHARGE_OPERATION), ['Location' => self::LOCATION]);
        $wire->signResponses = $tampered;
        $wire->tamperAfterSigning = $tampered;
        try {
            self::client($wire)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]));
            self::fail('An unsigned or tampered acknowledgement must be discarded.');
        } catch (\Anis\Partners\Verification\UnverifiableResponseException) {
            self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function emptyRecipientAnswers(): iterable
    {
        yield 'empty 202' => [202, ''];
        yield 'null 202' => [202, 'null'];
        yield 'empty 204' => [204, ''];
    }

    #[Test]
    #[DataProvider('emptyRecipientAnswers')]
    public function it_keeps_the_normal_empty_answer_exception_for_recipient_changes(int $status, string $body): void
    {
        $wire = self::wire($status, $body, ['Location' => self::LOCATION]);
        try {
            self::client($wire)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]));
            self::fail('An empty answer must not confirm the correction.');
        } catch (\Anis\Partners\Errors\EmptyBodyException $error) {
            self::assertSame($status, $error->status);
            self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        }
    }

    /** @return iterable<string, array{?string, int}> */
    public static function acknowledgementRetryDelays(): iterable
    {
        yield 'default' => [null, 5];
        yield 'published delay' => ['9', 9];
        yield 'zero delay' => ['0', 0];
        yield 'leading zeros' => ['00009', 9];
        yield 'unsupported value' => ['tomorrow', 5];
        yield 'out of range' => ['2147483648', 5];
    }

    #[Test]
    #[DataProvider('acknowledgementRetryDelays')]
    public function it_returns_the_same_processing_acknowledgement_for_create_and_recipient_update(?string $retryAfter, int $expectedDelay): void
    {
        $headers = ['Location' => self::LOCATION];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }
        $body = P::accepted('directRecharge', P::RECHARGE_OPERATION);
        $createWire = self::wire(202, $body, $headers);
        $patchWire = self::wire(202, $body, $headers);
        $created = self::client($createWire)->directRecharges()->create(P::WALLET, P::RECHARGE_OPERATION, self::request());
        $updated = self::client($patchWire)->directRecharges()->updateRecipient(P::RECHARGE_OPERATION, new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]));

        self::assertInstanceOf(DirectRechargeProcessing::class, $created);
        self::assertInstanceOf(DirectRechargeProcessing::class, $updated);
        self::assertSame($created::class, $updated::class);
        self::assertSame(get_object_vars($created), get_object_vars($updated));
        self::assertSame(P::RECHARGE_OPERATION, $updated->operationId);
        self::assertSame($expectedDelay, $updated->retryAfterSeconds);
        self::assertSame(self::LOCATION, $updated->location);
        self::assertNull($updated->read);
        self::assertCount(1, array_filter($patchWire->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        self::assertSame([], array_values(array_filter($patchWire->requests, static fn($request): bool => $request->getMethod() === 'GET' && $request->getUri()->getPath() === self::LOCATION)));
    }
}
