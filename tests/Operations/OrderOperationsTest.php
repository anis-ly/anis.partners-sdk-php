<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\InsufficientBalanceException;
use Anis\Partners\Errors\PriceChangedException;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\OrderNotPlaced;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Models\OrderProcessing;
use Anis\Partners\Models\OrderReplayed;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderOperationsTest extends TestCase
{
    private const WALLET = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
    private const OPERATION = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
    private const CARD = '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';

    /** @return iterable<string, array{int, string, array<string, string>, class-string<object>}> */
    public static function outcomeCases(): iterable
    {
        yield 'created' => [201, '{"operationId":"' . self::OPERATION . '","status":"completed","soldCards":[{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","voucher":"1234"}]}', [], OrderCompleted::class];
        yield 'processing' => [202, '{"operationId":"' . self::OPERATION . '","status":"processing"}', ['Retry-After' => '30', 'Location' => '/v1/orders/' . self::OPERATION], OrderProcessing::class];
        yield 'replayed' => [201, '{"operationId":"' . self::OPERATION . '","status":"completed"}', ['Idempotency-Replayed' => 'true'], OrderReplayed::class];
        yield 'not placed' => [409, '{"status":409,"code":"insufficient_balance"}', [], OrderNotPlaced::class];
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function doorRefusalCases(): iterable
    {
        foreach ([
            'invalid_credentials' => 401,
            'signature_expired' => 401,
            'insufficient_scope' => 403,
            'wallet_not_granted' => 403,
            'malformed_signed_request' => 400,
        ] as $code => $status) {
            yield 'create ' . $code => [$status, $code, false];
            yield 'resume ' . $code => [$status, $code, true];
        }
    }

    /** @return iterable<string, array{CreateOrderRequest, bool}> */
    public static function invalidOrderGuards(): iterable
    {
        $orders = [
            'zero quantity' => new CreateOrderRequest(self::CARD, 0, Money::of('10.5', 'LYD'), Money::of('0', 'LYD')),
            'zero unit price' => new CreateOrderRequest(self::CARD, 1, Money::of('0.000', 'LYD'), Money::of('0.000', 'LYD')),
            'currency mismatch' => new CreateOrderRequest(self::CARD, 1, Money::of('10', 'LYD'), Money::of('10', 'USD')),
            'total mismatch' => new CreateOrderRequest(self::CARD, 2, Money::of('10', 'LYD'), Money::of('19', 'LYD')),
        ];
        foreach ($orders as $name => $order) {
            yield 'create ' . $name => [$order, false];
            yield 'resume ' . $name => [$order, true];
        }
    }

    /** @param array<string, string> $headers @param class-string<object> $expected */
    #[Test]
    #[DataProvider('outcomeCases')]
    public function it_classifies_signed_order_answers(int $status, string $body, array $headers, string $expected): void
    {
        $wire = new SignedFakeWire();
        $wire->status = $status;
        $wire->body = $body;
        $wire->responseHeaders = ['X-Request-Id' => 'request-9'] + $headers;
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertSame($expected, $result::class);
        if ($result instanceof OrderProcessing) {
            self::assertSame(30, $result->retryAfterSeconds);
            self::assertSame('/v1/orders/' . self::OPERATION, $result->location);
        }
        if ($result instanceof OrderNotPlaced) {
            self::assertInstanceOf(InsufficientBalanceException::class, $result->refusal);
        }
    }

    #[Test]
    public function it_returns_completion_credentials_even_when_the_response_is_marked_replayed(): void
    {
        $wire = new SignedFakeWire();
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed","soldCards":[{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","voucher":"1234"}]}';
        $wire->responseHeaders = ['X-Request-Id' => 'request-10', 'Idempotency-Replayed' => 'true'];
        $result = self::client($wire)->orders()->resume(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderCompleted::class, $result);
        self::assertSame('1234', $result->credentials[0]->voucher);
        self::assertFalse($result->codesWithheld);
    }

    #[Test]
    public function it_classifies_each_true_replay_header_as_replayed(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed"}';
        $wire->responseHeaders = ['X-Request-Id' => 'request-replay', 'Idempotency-Replayed' => ['true', 'TRUE']];

        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderReplayed::class, $result);
    }

    #[Test]
    public function it_recognizes_a_true_replay_value_inside_a_comma_joined_header(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed"}';
        $wire->responseHeaders = ['X-Request-Id' => 'request-replay-joined', 'Idempotency-Replayed' => 'false, TRUE'];

        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderReplayed::class, $result);
    }

    #[Test]
    public function it_treats_each_true_replay_header_on_a_refusal_as_not_placed(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 409;
        $wire->body = '{"status":409,"code":"dependency_unavailable"}';
        $wire->responseHeaders = ['X-Request-Id' => 'request-refusal-replay', 'Idempotency-Replayed' => 'false, TRUE'];

        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderNotPlaced::class, $result);
        self::assertTrue($result->refusal->isReplayed);
    }

    #[Test]
    public function it_treats_a_completion_without_credentials_as_codes_withheld(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed"}';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderCompleted::class, $result);
        self::assertSame([], $result->credentials);
        self::assertTrue($result->codesWithheld);
    }

    #[Test]
    public function it_preserves_the_withheld_flag_and_external_reference(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed","codesWithheld":true,"externalReference":"INV-77"}';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderCompleted::class, $result);
        self::assertTrue($result->codesWithheld);
        self::assertSame('INV-77', $result->order->externalReference);
    }

    #[Test]
    public function it_keeps_a_price_change_as_a_typed_closed_refusal(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 409;
        $wire->body = '{"status":409,"code":"price_changed","requestId":"order-err-1"}';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderNotPlaced::class, $result);
        self::assertInstanceOf(PriceChangedException::class, $result->refusal);
        self::assertSame('order-err-1', $result->refusal->requestId);
    }

    #[Test]
    public function it_uses_retry_after_when_a_create_is_rate_limited(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 429;
        $wire->body = '{"status":429,"code":"rate_limited"}';
        $wire->responseHeaders = ['X-Request-Id' => 'rate-test', 'Retry-After' => '30'];
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame(30, $result->suggestedDelaySeconds);
    }

    #[Test]
    public function it_keeps_a_fresh_refusal_during_resume_unknown(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 429;
        $wire->body = '{"status":429,"code":"rate_limited"}';
        $result = self::client($wire)->orders()->resume(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame(5, $result->suggestedDelaySeconds);
    }

    #[Test]
    public function it_closes_a_replayed_door_refusal_without_resuming_it(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 403;
        $wire->body = '{"status":403,"code":"insufficient_scope"}';
        $wire->responseHeaders = ['X-Request-Id' => 'door-test', 'Idempotency-Replayed' => 'true'];
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderNotPlaced::class, $result);
        self::assertTrue($result->refusal->isReplayed);
    }

    #[Test]
    public function it_keeps_a_verified_success_with_unreadable_json_unknown(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertInstanceOf(\Anis\Partners\Errors\MalformedResponseException::class, $result->cause);
    }

    #[Test]
    public function it_keeps_an_empty_verified_success_unknown_with_an_internal_error(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame('internal_error', $result->cause instanceof \Anis\Partners\Errors\AnisApiException ? $result->cause->errorCode->value : null);
        self::assertStringContainsString('Empty body', $result->cause->getMessage());
    }

    #[Test]
    public function it_treats_a_null_success_body_as_an_empty_body(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = 'null';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertInstanceOf(\Anis\Partners\Errors\AnisApiException::class, $result->cause);
        self::assertSame('internal_error', $result->cause->errorCode->value);
        self::assertStringContainsString('Empty body', $result->cause->getMessage());
    }

    #[Test]
    public function it_keeps_a_dependency_refusal_open_for_recovery(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 503;
        $wire->body = '{"status":503,"code":"dependency_unavailable"}';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame(5, $result->suggestedDelaySeconds);
    }

    #[Test]
    public function it_keeps_create_and_resume_unknown_after_a_lost_answer(): void
    {
        $wire = new SignedFakeWire();
        $wire->failure = new \RuntimeException('connection dropped');
        $client = self::client($wire);
        $created = $client->orders()->create(self::WALLET, self::OPERATION, self::order());
        $resumed = $client->orders()->resume(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $created);
        self::assertInstanceOf(OrderOutcomeUnknown::class, $resumed);
        self::assertSame(5, $created->suggestedDelaySeconds);
        self::assertSame(self::OPERATION, $resumed->operationId);
    }

    #[Test]
    public function it_uses_a_minute_to_recover_a_door_refusal(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 403;
        $wire->body = '{"status":403,"code":"insufficient_scope"}';
        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame(60, $result->suggestedDelaySeconds);
    }

    #[Test]
    #[DataProvider('doorRefusalCases')]
    public function it_keeps_door_refusals_unknown_for_create_and_resume(int $status, string $code, bool $resume): void
    {
        $wire = new SignedFakeWire();
        $wire->status = $status;
        $wire->body = json_encode(['status' => $status, 'code' => $code], JSON_THROW_ON_ERROR);

        $operations = self::client($wire)->orders();
        $result = $resume
            ? $operations->resume(self::WALLET, self::OPERATION, self::order())
            : $operations->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame(60, $result->suggestedDelaySeconds);
    }

    #[Test]
    public function it_honors_a_zero_retry_after_for_a_door_refusal(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 403;
        $wire->body = '{"status":403,"code":"insufficient_scope"}';
        $wire->responseHeaders = ['X-Request-Id' => 'door-test', 'Retry-After' => '0'];

        $result = self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);
        self::assertSame(0, $result->suggestedDelaySeconds);
    }

    #[Test]
    public function it_refuses_non_positive_prices_before_opening_the_wire(): void
    {
        $wire = new SignedFakeWire();
        $client = self::client($wire);
        $order = new CreateOrderRequest(self::CARD, 2, Money::of('-0.001', 'LYD'), Money::of('-0.002', 'LYD'));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->orders()->create(self::WALLET, self::OPERATION, $order);
        } finally {
            self::assertCount(0, $wire->requests);
        }
    }

    #[Test]
    public function it_refuses_a_total_that_does_not_match_before_opening_the_wire(): void
    {
        $wire = new SignedFakeWire();
        $order = new CreateOrderRequest(self::CARD, 2, Money::of('10.5', 'LYD'), Money::of('20', 'LYD'));

        $this->expectException(\InvalidArgumentException::class);
        try {
            self::client($wire)->orders()->create(self::WALLET, self::OPERATION, $order);
        } finally {
            self::assertCount(0, $wire->requests);
        }
    }

    #[Test]
    #[DataProvider('invalidOrderGuards')]
    public function it_applies_local_order_guards_to_create_and_resume_without_sending(CreateOrderRequest $order, bool $resume): void
    {
        $wire = new SignedFakeWire();
        $operations = self::client($wire)->orders();

        try {
            if ($resume) {
                $operations->resume(self::WALLET, self::OPERATION, $order);
            } else {
                $operations->create(self::WALLET, self::OPERATION, $order);
            }
            self::fail('An invalid order must be refused by the local guard.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $wire->requests);
        }
    }

    #[Test]
    public function it_does_not_turn_a_signer_failure_into_an_unknown_order(): void
    {
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $signer = new class implements RequestSigner {
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

        try {
            $client->orders()->create(self::WALLET, self::OPERATION, self::order());
            self::fail('A signer error happened before a request could be sent.');
        } catch (RequestSigningException $exception) {
            self::assertSame('signer unavailable', $exception->getPrevious()?->getMessage());
            self::assertCount(0, $wire->requests);
        }
    }

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();
        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    private static function order(): CreateOrderRequest
    {
        return new CreateOrderRequest(self::CARD, 2, Money::of('10.5', 'LYD'), Money::of('21', 'LYD'));
    }
}
