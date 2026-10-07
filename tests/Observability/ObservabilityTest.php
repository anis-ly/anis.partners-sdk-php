<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Observability;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\EnrollmentState;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\OrderNotPlaced;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\Wallet;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Tests\Support\FixedNonceFactory;
use Anis\Partners\Tests\Support\RecordingLogger;
use Anis\Partners\Tests\Support\SignedFakeWire;
use Anis\Partners\Verification\SigningKeySet;
use Anis\Partners\Verification\UnverifiableResponseException;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class ObservabilityTest extends TestCase
{
    #[Test]
    public function it_records_non_empty_telemetry_without_revealing_order_secrets(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);

        $wire = new SignedFakeWire();
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($privateKey);
        self::assertTrue(openssl_pkey_export($privateKey, $knownPem));
        self::assertIsString($knownPem);
        $wire->status = 201;
        $wire->body = '{"operationId":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","status":"completed","soldCards":[{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","serialNumber":"secret-serial","voucher":"secret-voucher"}]}';
        $signer = new class (PemP256Signer::fromPem($knownPem)->forKey('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48')) implements RequestSigner {
            /** @var list<string> */
            public array $bases = [];

            public function __construct(private readonly RequestSigner $inner) {}

            public function keyId(): string
            {
                return $this->inner->keyId();
            }

            public function sign(#[\SensitiveParameter] string $data): string
            {
                $this->bases[] = $data;

                return $this->inner->sign($data);
            }
        };
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        try {
            $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $signer, $wire, $factory, $factory, logger: $logger, nonceFactory: new FixedNonceFactory('known-nonce-sentinel'));
            $orderResult = $client->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );
            $wire->body = '{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","serialNumber":"secret-serial","voucher":"secret-voucher"}';
            $revealResult = $client->ownedCards()->reveal('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046');
            $wire->body = '{"state":"pendingApproval"}';
            $enrollment = EnrollmentClient::create('https://partners.example', '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', 'known-enrollment-token', $wire, $factory, $factory, logger: $logger)->get();
            self::assertSame('pendingApproval', $enrollment->state);
            $wire->body = '{"operationId":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","status":"completed"}';
            $wire->tamperAfterSigning = true;
            try {
                $client->orders()->get('9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34');
                self::fail('A changed answer must not be returned.');
            } catch (UnverifiableResponseException $exception) {
                $responseFailure = $exception->getMessage();
                for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
                    $responseFailure .= $cause->getMessage() . $cause->getTraceAsString();
                }
            }
        } finally {
            $scope->detach();
        }

        self::assertNotEmpty($signals);
        self::assertNotEmpty($logger->records);
        self::assertNotNull($wire->activeSpanAtSend);
        $captured = json_encode([$signals, $logger->records], JSON_THROW_ON_ERROR);
        ob_start();
        var_dump($orderResult, $revealResult);
        $dump = (string) ob_get_clean();
        $diagnostics = $dump . print_r([$orderResult, $revealResult], true) . $responseFailure;
        $storageExports = var_export([$orderResult, $revealResult], true) . serialize([$orderResult, $revealResult]);
        self::assertStringContainsString('secret-voucher', $storageExports, 'Partner-owned storage exports intentionally retain returned codes.');
        foreach (['known-private-pem' => $knownPem, 'known-enrollment-token' => 'known-enrollment-token', 'secret-voucher' => 'secret-voucher', 'secret-serial' => 'secret-serial', 'fixed nonce' => 'known-nonce-sentinel', 'order Signature header' => $wire->requests[0]->getHeaderLine('Signature'), 'order Signature-Input header' => $wire->requests[0]->getHeaderLine('Signature-Input'), 'reveal Signature header' => $wire->requests[2]->getHeaderLine('Signature'), 'reveal Signature-Input header' => $wire->requests[2]->getHeaderLine('Signature-Input'), 'signature base' => $signer->bases[0], 'reveal signature base' => $signer->bases[1]] as $label => $secret) {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $captured, $label . ' reached telemetry or a log.');
            self::assertStringNotContainsString($secret, $diagnostics, $label . ' reached diagnostic result output.');
        }
        foreach ($wire->requests as $request) {
            foreach (['Signature', 'Signature-Input'] as $header) {
                $secret = $request->getHeaderLine($header);
                if ($secret !== '') {
                    self::assertStringNotContainsString($secret, $captured, $header . ' reached a telemetry signal or log.');
                    self::assertStringNotContainsString($secret, $diagnostics, $header . ' reached diagnostic result output.');
                }
            }
        }
        foreach ($signer->bases as $base) {
            self::assertStringNotContainsString($base, $captured, 'A request signature base reached telemetry or a log.');
            self::assertStringNotContainsString($base, $diagnostics, 'A request signature base reached diagnostic result output.');
        }
        self::assertStringContainsString('anis.partners.request.duration', $captured);
        self::assertStringContainsString('anis.partners.signature.duration', $captured);
        self::assertStringContainsString('anis.partners.order.outcomes', $captured);
        self::assertStringContainsString('anis.route', $captured);
        self::assertStringContainsString('anis.operation_id', $captured);
        self::assertStringContainsString('completed', $captured);
        self::assertStringContainsString('span_status', $captured);
        self::assertStringContainsString('"error.type":"unverifiable"', $captured);
        self::assertStringNotContainsString('ContentDigestMismatch', $captured);
    }

    #[Test]
    public function it_keeps_an_enrollment_token_out_of_logs_spans_and_metrics(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->body = '{"state":"pendingApproval"}';
        $logger = new RecordingLogger();
        $factory = new HttpFactory();

        try {
            $state = EnrollmentClient::create('https://partners.example', '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', 'enrollment-token-sentinel', $wire, $factory, $factory, logger: $logger)->get();
        } finally {
            $scope->detach();
        }

        self::assertSame('pendingApproval', $state->state);
        self::assertSame('Enrollment enrollment-token-sentinel', $wire->requests[0]->getHeaderLine('Authorization'));
        self::assertNotEmpty($signals);
        self::assertNotEmpty($logger->records);
        $telemetry = json_encode([$signals, $logger->records], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('enrollment-token-sentinel', $telemetry);
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
            AnisPartnersClient::create(new ClientOptions('https://partners.example', name: 'profile-reader'), $wire->requestSigner(), $wire, $factory, $factory)
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
        self::assertStringContainsString('"unit":"ms"', $encoded);
        self::assertSame('profile-reader', $signals['anis.client']);
        self::assertStringContainsString('profile-reader', $encoded);
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
        self::assertStringNotContainsString('"anis.order.outcome":"not_placed"', $encoded);
        self::assertStringNotContainsString('"anis.order.outcome":"unknown"', $encoded);
        self::assertStringNotContainsString('"error.type"', $encoded);
        self::assertNotContains(1004, array_column($logger->records, 'context.event_id'));
        self::assertStringContainsString('POST /v1/wallets/{walletId}/orders response status 409', $this->recordWithEvent($logger, 1001)['message']);
        self::assertMatchesRegularExpression('/ in [0-9.]+ ms, request req-test\z/', $this->recordWithEvent($logger, 1001)['message']);
        self::assertStringContainsString('req-test', $this->recordWithEvent($logger, 1001)['message']);
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
        self::assertStringContainsString('operation 9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', $this->recordWithEvent($logger, 1008)['message']);
        self::assertStringContainsString('invalid_credentials', $this->recordWithEvent($logger, 1008)['message']);
        self::assertStringContainsString('resume it with the same operation id, never a new one', $this->recordWithEvent($logger, 1008)['message']);
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
        self::assertStringContainsString('content_digest_mismatch', json_encode($signals, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('ContentDigestMismatch', json_encode($signals, JSON_THROW_ON_ERROR));
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
    public function it_classifies_psr_network_timeouts_separately_from_connection_failures(): void
    {
        $signals = [];
        $scope = $this->installTelemetry($signals);
        $wire = new SignedFakeWire();
        $wire->failure = new TimeoutClientException(new \GuzzleHttp\Psr7\Request('POST', 'https://partners.example'));
        try {
            AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, new HttpFactory(), new HttpFactory())
                ->profile()->get();
        } catch (\Psr\Http\Client\NetworkExceptionInterface) {
            // Profile reads propagate the no-answer transport exception after recording it.
        } finally {
            $scope->detach();
        }

        self::assertStringContainsString('"error.type":"timeout"', json_encode($signals, JSON_THROW_ON_ERROR));
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
        self::assertCount(1, $outcomes);
        self::assertSame('unknown', $outcomes[0]['attributes']['anis.order.outcome']);
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

    #[Test]
    public function it_keeps_known_nonce_and_signature_base_out_of_signing_exception_chains(): void
    {
        $wire = new SignedFakeWire();
        $logger = new RecordingLogger();
        $signer = new class implements RequestSigner {
            public string $base = '';
            public function keyId(): string
            {
                return '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';
            }
            public function sign(#[\SensitiveParameter] string $data): string
            {
                $this->base = $data;
                throw new \RuntimeException('vault signing failed');
            }
        };
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(
            new ClientOptions('https://partners.example'),
            $signer,
            $wire,
            $factory,
            $factory,
            logger: $logger,
            nonceFactory: new FixedNonceFactory('exception-nonce-sentinel'),
        );
        try {
            $client->orders()->create(
                '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')),
            );
            self::fail('A failing signer must be wrapped as a request signing error.');
        } catch (RequestSigningException $exception) {
            $chain = '';
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                $chain .= $cause->getMessage() . $cause->getTraceAsString();
            }
            $previous = $exception->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $previous);
            self::assertSame([], $wire->requests);
            foreach (['exception-nonce-sentinel', $signer->base] as $known) {
                self::assertNotSame('', $known);
                self::assertStringNotContainsString($known, json_encode($logger->records, JSON_THROW_ON_ERROR));
                self::assertStringNotContainsString($known, $chain);
            }
            self::assertStringContainsString('vault signing failed', $chain);
        }
    }

    #[Test]
    #[DataProvider('throwingSignalCases')]
    public function it_preserves_each_result_when_a_host_signal_throws(string $path, string $failure): void
    {
        $scope = $failure === 'logger' ? null : $this->installThrowingTelemetry($failure);
        $logger = $failure === 'logger' ? new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('host logger failed');
            }
        } : null;
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        try {
            match ($path) {
                'read' => self::assertSame('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', $this->readWith($wire, $factory, $logger)->id),
                'reveal' => self::assertInstanceOf(RevealedCredential::class, $this->revealWith($wire, $factory, $logger)),
                'completed' => self::assertSame('completed-code', $this->completedWith($wire, $factory, $logger)->credentials[0]->voucher),
                'unknown' => self::assertInstanceOf(OrderOutcomeUnknown::class, $this->unknownWith($wire, $factory, $logger)),
                'refusal' => self::assertInstanceOf(OrderNotPlaced::class, $this->refusalWith($wire, $factory, $logger)),
                'enrollment' => self::assertSame('pendingApproval', $this->enrollmentWith($wire, $factory, $logger)->state),
                'key_fetch' => self::assertCount(1, $this->fetchKeysWith($wire, $factory, $logger)->keys),
                default => throw new \LogicException('Unknown test case.'),
            };
        } finally {
            $scope?->detach();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function throwingSignalCases(): iterable
    {
        foreach (['read', 'reveal', 'completed', 'unknown', 'refusal', 'enrollment', 'key_fetch'] as $path) {
            foreach (['logger', 'meter', 'tracer'] as $failure) {
                yield $path . ' with throwing ' . $failure => [$path, $failure];
            }
        }
    }

    private function installThrowingTelemetry(string $failure): ScopeInterface
    {
        $context = Context::getCurrent();
        if ($failure === 'tracer') {
            $provider = $this->createMock(TracerProviderInterface::class);
            $provider->method('getTracer')->willThrowException(new \RuntimeException('tracer failed'));
            $context = $context->with(ContextKeys::tracerProvider(), $provider);
        } else {
            $provider = $this->createMock(MeterProviderInterface::class);
            $provider->method('getMeter')->willThrowException(new \RuntimeException('meter failed'));
            $context = $context->with(ContextKeys::meterProvider(), $provider);
        }

        return $context->activate();
    }

    private function readWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): Wallet
    {
        $wire->body = '{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26","name":"Main","currency":"LYD","balance":{"amount":"1.000","currency":"LYD"}}';

        return $this->clientWith($wire, $factory, $logger)->wallets()->get('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26');
    }

    private function revealWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): RevealedCredential
    {
        $wire->body = '{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","serialNumber":"completed-serial","voucher":"completed-code"}';

        return $this->clientWith($wire, $factory, $logger)->ownedCards()->reveal('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046');
    }

    private function completedWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): OrderCompleted
    {
        $wire->status = 201;
        $wire->body = '{"operationId":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","status":"completed","soldCards":[{"soldCardId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046","voucher":"completed-code"}]}';

        $result = $this->clientWith($wire, $factory, $logger)->orders()->create('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')));
        self::assertInstanceOf(OrderCompleted::class, $result);

        return $result;
    }

    private function unknownWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): OrderOutcomeUnknown
    {
        $wire->failure = new class extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};

        $result = $this->clientWith($wire, $factory, $logger)->orders()->create('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')));
        self::assertInstanceOf(OrderOutcomeUnknown::class, $result);

        return $result;
    }

    private function refusalWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): OrderNotPlaced
    {
        $wire->status = 409;
        $wire->body = '{"status":409,"code":"insufficient_balance"}';

        $result = $this->clientWith($wire, $factory, $logger)->orders()->create('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')));
        self::assertInstanceOf(OrderNotPlaced::class, $result);

        return $result;
    }

    private function enrollmentWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): EnrollmentState
    {
        $wire->body = '{"state":"pendingApproval"}';

        return EnrollmentClient::create('https://partners.example', '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', 'enrollment-test-token', $wire, $factory, $factory, logger: $logger)->get();
    }

    private function fetchKeysWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): SigningKeySet
    {
        return (new \Anis\Partners\Verification\HttpSigningKeySource($wire, $factory, 'https://partners.example', logger: $logger))->get();
    }

    private function clientWith(SignedFakeWire $wire, HttpFactory $factory, ?LoggerInterface $logger): AnisPartnersClient
    {
        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory, logger: $logger);
    }

    /** @param array<array-key, mixed> $signals */
    private function installTelemetry(array &$signals): ScopeInterface
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('setAttribute')->willReturnCallback(static function (string $name, mixed $value) use (&$signals, $span): SpanInterface {
            $signals[$name] = $value;
            return $span;
        });
        $span->method('setStatus')->willReturnCallback(static function (string $code, ?string $description = null) use (&$signals, $span): SpanInterface {
            $signals[] = ['span_status' => $code, 'description' => $description];
            return $span;
        });
        $span->method('addEvent')->willReturnCallback(static function (string $name, iterable $attributes = [], ?int $timestamp = null) use (&$signals, $span): SpanInterface {
            $signals[] = ['span_event' => $name, 'attributes' => iterator_to_array($attributes), 'timestamp' => $timestamp];
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

final class TimeoutClientException extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface
{
    public function __construct(private readonly \Psr\Http\Message\RequestInterface $request)
    {
        parent::__construct('timeout');
    }

    public function getRequest(): \Psr\Http\Message\RequestInterface
    {
        return $this->request;
    }
}
