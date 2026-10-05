<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Observability;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\Money;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Tests\Support\RecordingLogger;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Instrumentation\ContextKeys;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ObservabilityTest extends TestCase
{
    #[Test]
    public function it_records_non_empty_telemetry_without_revealing_order_secrets(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);

        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","status":"completed","soldCards":[{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","voucher":"secret-voucher"}]}';
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        try {
            $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger);
            $client->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );
        } finally {
            $scope->detach();
        }

        self::assertNotEmpty($signals);
        self::assertNotEmpty($logger->records);
        $captured = json_encode([$signals, $logger->records], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-voucher', $captured);
        self::assertStringContainsString('anis.partners.request.duration', $captured);
        self::assertStringContainsString('anis.partners.signature.duration', $captured);
        self::assertStringContainsString('anis.partners.order.outcomes', $captured);
        self::assertStringContainsString('anis.route', $captured);
        self::assertStringContainsString('anis.operation_id', $captured);
        self::assertStringContainsString('completed', $captured);
    }

    #[Test]
    public function it_returns_verified_order_credentials_when_the_host_logger_throws(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","status":"completed","soldCards":[{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","voucher":"order-secret-code"}]}';
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('host logger failed');
            }
        };
        $factory = new HttpFactory();
        $result = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger)
            ->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );

        self::assertInstanceOf(\Anis\Partners\Models\OrderCompleted::class, $result);
        self::assertSame('order-secret-code', $result->credentials[0]->voucher);
    }

    #[Test]
    public function it_records_the_route_template_and_separate_request_and_signature_durations(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->body = '{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26","name":"Main","currency":"LYD","balance":{"amount":"1.000","currency":"LYD"}}';
        $factory = new HttpFactory();
        try {
            AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory)
                ->wallets()->get('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26');
        } finally {
            $scope->detach();
        }

        self::assertSame('/v1/wallets/{walletId}', $signals['anis.route']);
        self::assertSame('GET', $signals['http.request.method']);
        self::assertSame(200, $signals['http.response.status_code']);
        $encoded = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('anis.partners.request.duration', $encoded);
        self::assertStringContainsString('anis.partners.signature.duration', $encoded);
        self::assertStringContainsString('SafeRead', $encoded);
    }

    #[Test]
    public function it_logs_a_verified_refusal_with_its_code_and_request_id(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->status = 409;
        $wire->body = '{"status":409,"code":"insufficient_balance","requestId":"req-refused"}';
        $wire->responseHeaders['Idempotency-Replayed'] = 'true';
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        try {
            $result = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger)
                ->orders()->create(
                    '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                    '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                    new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
                );
        } finally {
            $scope->detach();
        }

        self::assertInstanceOf(\Anis\Partners\Models\OrderNotPlaced::class, $result);
        self::assertTrue($result->refusal->isReplayed);
        self::assertSame('insufficient_balance', $signals['anis.error.code']);
        self::assertContains('anis.error.code', array_keys($signals));
        $encoded = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"anis.order.outcome":"not_placed"', $encoded);
        self::assertStringNotContainsString('"anis.order.outcome":"unknown"', $encoded);
        self::assertSame(1002, $this->recordWithEvent($logger, 1002)['context']['event_id']);
        self::assertSame('insufficient_balance', $this->recordWithEvent($logger, 1002)['context']['code']);
        self::assertSame('req-refused', $this->recordWithEvent($logger, 1002)['context']['request_id']);
    }

    #[Test]
    public function it_counts_an_access_refusal_on_create_as_unknown_and_logs_it(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->status = 401;
        $wire->body = '{"status":401,"code":"invalid_credentials"}';
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        try {
            $result = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger)
                ->orders()->create(
                    '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                    '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                    new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
                );
        } finally {
            $scope->detach();
        }

        self::assertInstanceOf(\Anis\Partners\Models\OrderOutcomeUnknown::class, $result);
        self::assertStringContainsString('"anis.order.outcome":"unknown"', json_encode($signals, JSON_THROW_ON_ERROR));
        self::assertSame('invalid_credentials', $this->recordWithEvent($logger, 1002)['context']['code']);
        self::assertSame(1008, $this->recordWithEvent($logger, 1008)['context']['event_id']);
    }

    #[Test]
    public function it_counts_and_logs_an_unverifiable_response_before_discarding_it(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->tamperAfterSigning = true;
        $factory = new HttpFactory();
        try {
            $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
            $result = $client->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );
        } finally {
            $scope->detach();
        }

        self::assertInstanceOf(\Anis\Partners\Models\OrderOutcomeUnknown::class, $result);
        self::assertInstanceOf(\Anis\Partners\Verification\UnverifiableResponseException::class, $result->cause);
        self::assertArrayHasKey('anis.partners.response.verification.failures', $this->instrumentNames($signals));
        self::assertStringContainsString('"anis.order.outcome":"unknown"', json_encode($signals, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_counts_an_order_without_a_usable_answer_as_unknown(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->failure = new class extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        try {
            $result = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger)
                ->orders()->create(
                    '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                    '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                    new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
                );
        } finally {
            $scope->detach();
        }

        self::assertInstanceOf(\Anis\Partners\Models\OrderOutcomeUnknown::class, $result);
        self::assertContains('anis.partners.order.outcomes', array_keys($this->instrumentNames($signals)));
        $encoded = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"anis.order.outcome":"unknown"', $encoded);
        self::assertStringContainsString('"error.type":"connection"', $encoded);
        self::assertSame(1007, $this->recordWithEvent($logger, 1007)['context']['event_id']);
        self::assertSame(1008, $this->recordWithEvent($logger, 1008)['context']['event_id']);
    }

    #[Test]
    public function it_reports_empty_success_bodies_as_unknown_with_an_internal_error(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = 'null';
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        try {
            $result = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger)
                ->orders()->create(
                    '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                    '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                    new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
                );
        } finally {
            $scope->detach();
        }

        self::assertInstanceOf(\Anis\Partners\Models\OrderOutcomeUnknown::class, $result);
        self::assertInstanceOf(\Anis\Partners\Errors\AnisApiException::class, $result->cause);
        self::assertSame('internal_error', $result->cause->errorCode->value);
        $encoded = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"error.type":"empty_body"', $encoded);
        self::assertStringContainsString('"anis.error.code":"internal_error"', $encoded);
    }

    #[Test]
    public function it_does_not_count_a_final_refusal_as_an_unknown_order(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->status = 503;
        $wire->body = '{"status":503,"code":"dependency_unavailable"}';
        $wire->responseQueue = [
            ['status' => 503, 'body' => '{"status":503,"code":"dependency_unavailable"}', 'headers' => ['X-Request-Id' => 'req-unknown']],
            ['status' => 409, 'body' => '{"status":409,"code":"insufficient_balance"}', 'headers' => ['X-Request-Id' => 'req-final']],
        ];
        $factory = new HttpFactory();
        try {
            $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
            $unknown = $client->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );
            $final = $client->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b35',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );
        } finally {
            $scope->detach();
        }

        self::assertInstanceOf(\Anis\Partners\Models\OrderOutcomeUnknown::class, $unknown);
        self::assertInstanceOf(\Anis\Partners\Models\OrderNotPlaced::class, $final);
        $outcomes = array_values(array_filter($signals, static fn(mixed $signal): bool => is_array($signal) && is_array($signal['attributes'] ?? null) && isset($signal['attributes']['anis.order.outcome'])));
        self::assertCount(2, $outcomes);
        self::assertSame('unknown', $outcomes[0]['attributes']['anis.order.outcome']);
        self::assertSame('not_placed', $outcomes[1]['attributes']['anis.order.outcome']);
    }

    #[Test]
    public function it_names_a_signing_failure_without_counting_an_unknown_order(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $signer = new class implements RequestSigner {
            public function keyId(): string
            {
                return '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';
            }
            public function sign(string $data): string
            {
                throw new \RuntimeException('signer unavailable');
            }
        };
        $factory = new HttpFactory();
        try {
            $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $signer, $wire, $factory, $factory);
            try {
                $client->orders()->create(
                    '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                    '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                    new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
                );
                self::fail('A failing signer must not make an order call.');
            } catch (\Anis\Partners\Signing\RequestSigningException) {
                self::assertSame([], $wire->requests);
            }
        } finally {
            $scope->detach();
        }

        $encoded = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"error.type":"signing"', $encoded);
        self::assertArrayNotHasKey('anis.partners.order.outcomes', $this->instrumentNames($signals));
    }

    /** @param array<array-key, mixed> $signals */
    private function installTelemetry(array &$signals): ScopeInterface
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('setAttribute')->willReturnCallback(static function (string $name, mixed $value) use (&$signals, $span): SpanInterface {
            $signals[$name] = $value;
            return $span;
        });
        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $spanBuilder->method('setSpanKind')->willReturnSelf();
        $spanBuilder->method('setAttributes')->willReturnCallback(static function (iterable $attributes) use (&$signals, $spanBuilder): SpanBuilderInterface {
            foreach ($attributes as $key => $value) {
                if (is_string($key)) {
                    $signals[$key] = $value;
                }
            }
            return $spanBuilder;
        });
        $spanBuilder->method('startSpan')->willReturn($span);
        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($spanBuilder);
        $tracers = $this->createMock(TracerProviderInterface::class);
        $tracers->method('getTracer')->willReturn($tracer);
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('record')->willReturnCallback(static function (int|float $value, iterable $attributes = []) use (&$signals): void {
            $signals[] = ['measurement' => $value, 'attributes' => iterator_to_array($attributes)];
        });
        $counter = $this->createMock(CounterInterface::class);
        $counter->method('add')->willReturnCallback(static function (int|float $value, iterable $attributes = []) use (&$signals): void {
            $signals[] = ['measurement' => $value, 'attributes' => iterator_to_array($attributes)];
        });
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createHistogram')->willReturnCallback(static function (string $name, ?string $unit = null, ?string $description = null, array $advisory = []) use (&$signals, $histogram): HistogramInterface {
            $signals[] = ['instrument' => $name, 'unit' => $unit];
            return $histogram;
        });
        $meter->method('createCounter')->willReturnCallback(static function (string $name, ?string $unit = null, ?string $description = null, array $advisory = []) use (&$signals, $counter): CounterInterface {
            $signals[] = ['instrument' => $name, 'unit' => $unit];
            return $counter;
        });
        $meters = $this->createMock(MeterProviderInterface::class);
        $meters->method('getMeter')->willReturn($meter);
        $context = Context::getCurrent()
            ->with(ContextKeys::tracerProvider(), $tracers)
            ->with(ContextKeys::meterProvider(), $meters);

        return $context->activate();
    }

    /** @param array<array-key, mixed> $signals
     * @return array<string, string>
     */
    private function instrumentNames(array $signals): array
    {
        $names = [];
        foreach ($signals as $signal) {
            if (is_array($signal) && is_string($signal['instrument'] ?? null)) {
                $names[$signal['instrument']] = $signal['instrument'];
            }
        }

        return $names;
    }

    /** @return array{level: mixed, message: string, context: array<array-key, mixed>} */
    private function recordWithEvent(RecordingLogger $logger, int $eventId): array
    {
        foreach ($logger->records as $record) {
            if (($record['context']['event_id'] ?? null) === $eventId) {
                return $record;
            }
        }

        self::fail('Expected structured log event ' . $eventId . '.');
    }
}
