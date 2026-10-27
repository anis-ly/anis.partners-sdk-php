<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Verification;

use Anis\Partners\Models\DirectRecharge;
use Anis\Partners\Models\MeterCharge;
use Anis\Partners\Models\WebhookEventType;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Anis\Partners\Tests\Support\FakeHttpClient;
use Anis\Partners\Tests\Support\FrozenClock;
use Anis\Partners\Tests\Support\JsonFixture;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use Anis\Partners\Tests\Support\StaticSigningKeySource;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\PartnerJwk;
use Anis\Partners\Verification\PartnerWebhookVerifier;
use Anis\Partners\Verification\SigningKeySet;
use Anis\Partners\Verification\UnverifiableWebhookException;
use Anis\Partners\Verification\VerifiableWebhook;
use Anis\Partners\Verification\WebhookVerificationFailure;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Signs deliveries in process with a throwaway key, so the verifier is tested beyond the vendored corpus. */
final class PartnerWebhookVerifierTest extends TestCase
{
    private const URL = 'https://pos.example.ly/hooks/anis?store=7&lang=ar';
    private const APPLICATION = '9b2d1241-413e-4ae8-ae32-b15c58dbe101';
    private const EVENT = '4bceba8e-55d1-59ec-b560-6c1e08f2ae3a';
    private const KEY_ID = 'partner-webhook-signing/11111111111111111111111111111111';
    private const NOW = 1792484045;

    private \OpenSSLAsymmetricKey $key;
    private StaticSigningKeySource $source;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $ec = $details['ec'];
        self::assertIsArray($ec);
        self::assertIsString($ec['x']);
        self::assertIsString($ec['y']);
        $jwk = [
            'kty' => 'EC', 'crv' => 'P-256', 'kid' => self::KEY_ID, 'use' => 'sig', 'alg' => 'ES256', 'purpose' => 'webhook', 'status' => 'active',
            'x' => rtrim(strtr(base64_encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '='),
            'y' => rtrim(strtr(base64_encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '='),
            'notBefore' => '2026-10-20T08:12:20Z',
        ];
        $this->source = new StaticSigningKeySource(SigningKeySet::fromJson(json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)));
    }

    private function verifier(): PartnerWebhookVerifier
    {
        return new PartnerWebhookVerifier($this->source, self::URL, self::APPLICATION, new FrozenClock(self::NOW));
    }

    /** @param array<string, mixed> $data */
    private static function body(string $type, array $data): string
    {
        return json_encode(['id' => self::EVENT, 'type' => $type, 'occurredAt' => '2026-10-20T08:13:02Z', 'applicationId' => self::APPLICATION, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function delivery(string $body, int $created = self::NOW - 5): VerifiableWebhook
    {
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
        $parameters = '("@method" "@target-uri" "content-digest" "webhook-id" "anis-webhook-attempt" "anis-application-id");created=' . $created . ';expires=' . ($created + 300) . ';keyid="' . self::KEY_ID . '";alg="ecdsa-p256-sha256";tag="anis-partner-webhook-v1"';
        $base = "\"@method\": POST\n\"@target-uri\": " . self::URL . "\n\"content-digest\": " . $digest . "\n\"webhook-id\": " . self::EVENT . "\n\"anis-webhook-attempt\": 1\n\"anis-application-id\": " . self::APPLICATION . "\n\"@signature-params\": " . $parameters;
        if (!openssl_sign($base, $der, $this->key, OPENSSL_ALGO_SHA256) || !is_string($der)) {
            throw new \RuntimeException('Could not sign the webhook test body.');
        }

        return new VerifiableWebhook('POST', self::URL, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Digest' => $digest,
            'Signature-Input' => 'sig1=' . $parameters,
            'Signature' => 'sig1=:' . base64_encode(EcdsaSignatureFormat::derToP1363($der)) . ':',
            'webhook-id' => self::EVENT,
            'anis-webhook-attempt' => '1',
            'anis-application-id' => self::APPLICATION,
        ], $body);
    }

    #[Test]
    public function it_accepts_and_parses_a_meter_delivery_with_its_tokens(): void
    {
        $envelope = $this->verifier()->verifyAndParse($this->delivery(self::body('meterCharge.succeeded', P::meterCharge('succeeded'))));

        self::assertSame(WebhookEventType::MeterChargeSucceeded, $envelope->type);
        self::assertInstanceOf(MeterCharge::class, $envelope->data);
        self::assertCount(3, $envelope->data->receipt->tokens ?? []);
        self::assertSame(self::EVENT, $envelope->id);
    }

    #[Test]
    public function it_accepts_a_direct_recharge_delivery(): void
    {
        $envelope = $this->verifier()->verifyAndParse($this->delivery(self::body('directRecharge.completed', P::directRecharge('completed'))));

        self::assertInstanceOf(DirectRecharge::class, $envelope->data);
    }

    #[Test]
    public function it_exposes_the_two_control_types_without_typed_operation_data(): void
    {
        $verification = $this->verifier()->verifyAndParse($this->delivery(self::body('webhook_endpoint.verification', ['challenge' => 'abc', 'generation' => 7])));
        $test = $this->verifier()->verifyAndParse($this->delivery(self::body('webhook_endpoint.test', ['message' => 'hello'])));

        self::assertSame(WebhookEventType::EndpointVerification, $verification->type);
        self::assertSame('abc', $verification->rawData['challenge']);
        self::assertNull($verification->data);
        self::assertSame(WebhookEventType::EndpointTest, $test->type);
        self::assertFalse($test->type->isOperational());
    }

    #[Test]
    public function it_refuses_a_known_type_whose_data_is_not_the_read_model(): void
    {
        $this->verifier()->verify($this->delivery(self::body('meterCharge.succeeded', ['status' => 'succeeded'])));
        try {
            $this->verifier()->verifyAndParse($this->delivery(self::body('meterCharge.succeeded', ['status' => 'succeeded'])));
            self::fail('Malformed known data must not be returned.');
        } catch (UnverifiableWebhookException $error) {
            self::assertSame(WebhookVerificationFailure::EnvelopeInvalid, $error->failure());
        }
    }

    #[Test]
    public function it_refuses_a_duplicate_root_member_after_verification(): void
    {
        $body = '{"id":"' . self::EVENT . '","type":"x.y","type":"x.z","occurredAt":"2026-10-20T08:13:02Z","applicationId":"' . self::APPLICATION . '","data":{}}';
        $delivery = $this->delivery($body);
        $this->verifier()->verify($delivery);

        $this->expectException(UnverifiableWebhookException::class);
        $this->verifier()->verifyAndParse($delivery);
    }

    #[Test]
    public function it_never_trusts_the_host_or_forwarded_headers_for_what_it_expects(): void
    {
        $delivery = $this->delivery(self::body('webhook_endpoint.test', ['message' => 'hi']));
        $forged = new VerifiableWebhook('POST', 'https://evil.example/hooks/anis', $delivery->headers + ['Host' => 'evil.example', 'X-Forwarded-Host' => 'pos.example.ly'], $delivery->body);

        try {
            $this->verifier()->verify($forged);
            self::fail('A delivery to another address must be refused.');
        } catch (UnverifiableWebhookException $error) {
            self::assertSame(WebhookVerificationFailure::TargetUriMismatch, $error->failure());
        }
    }

    #[Test]
    public function it_refreshes_the_key_document_once_for_an_unknown_key_and_then_refuses(): void
    {
        $delivery = $this->delivery(self::body('webhook_endpoint.test', ['message' => 'hi']));
        $headers = $delivery->headers;
        $input = $headers['Signature-Input'];
        self::assertIsString($input);
        $headers['Signature-Input'] = str_replace(self::KEY_ID, 'partner-webhook-signing/99999999999999999999999999999999', $input);
        try {
            $this->verifier()->verify(new VerifiableWebhook('POST', self::URL, $headers, $delivery->body));
            self::fail('An unknown key must be refused.');
        } catch (UnverifiableWebhookException $error) {
            self::assertSame(WebhookVerificationFailure::UnknownKey, $error->failure());
            self::assertSame(1, $this->source->refreshCalls);
        }
    }

    #[Test]
    public function it_keeps_raw_bytes_and_signatures_out_of_dumps_and_exceptions(): void
    {
        $delivery = $this->delivery(self::body('webhook_endpoint.test', ['message' => 'SECRET-BODY']));
        ob_start();
        var_dump($delivery);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('SECRET-BODY', $dump);
        self::assertStringNotContainsString('sig1=:', $dump);
        $tampered = new VerifiableWebhook('POST', self::URL, $delivery->headers, $delivery->body . ' ');
        try {
            $this->verifier()->verify($tampered);
            self::fail('A changed body must be refused.');
        } catch (UnverifiableWebhookException $error) {
            self::assertStringNotContainsString('SECRET-BODY', $error->getMessage());
            self::assertSame(WebhookVerificationFailure::ContentDigestMismatch, $error->failure());
        }
    }

    #[Test]
    public function it_marks_the_delivery_parameters_as_sensitive(): void
    {
        foreach (['verify', 'verifyAndParse'] as $method) {
            $parameter = (new \ReflectionMethod(PartnerWebhookVerifier::class, $method))->getParameters()[0];
            self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
        }
    }

    #[Test]
    public function it_keeps_the_purpose_member_of_a_published_key(): void
    {
        $jwk = PartnerJwk::fromArray(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'a', 'y' => 'b', 'kid' => 'k', 'purpose' => 'webhook', 'status' => 'active']);

        self::assertSame('webhook', $jwk->purpose);
        self::assertSame('webhook', $jwk->toArray()['purpose']);
        self::assertNull(PartnerJwk::fromArray(['kty' => 'EC'])->purpose);
    }

    #[Test]
    public function it_has_a_failure_member_for_each_of_the_twenty_two_wire_reasons(): void
    {
        self::assertCount(22, WebhookVerificationFailure::cases());
        self::assertSame(22, count(array_unique(array_map(static fn(WebhookVerificationFailure $case): string => $case->value, WebhookVerificationFailure::cases()))));
    }

    #[Test]
    public function it_redacts_actual_exception_trace_arguments_on_both_public_paths(): void
    {
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $delivery = $this->delivery(self::body('webhook_endpoint.test', ['message' => 'SECRET-BODY']));
        foreach (['verify', 'verifyAndParse'] as $method) {
            try {
                $tampered = new VerifiableWebhook('POST', self::URL, $delivery->headers, $delivery->body . ' ');
                if ($method === 'verify') {
                    $this->verifier()->verify($tampered);
                } else {
                    $this->verifier()->verifyAndParse($tampered);
                }
                self::fail('A changed body must fail.');
            } catch (UnverifiableWebhookException $error) {
                $frames = array_values(array_filter($error->getTrace(), static fn(array $frame): bool => ($frame['class'] ?? null) === PartnerWebhookVerifier::class));
                self::assertCount(3, $frames);
                foreach ($frames as $frame) {
                    if (in_array($frame['function'], ['check', 'verify', 'verifyAndParse'], true)) {
                        self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0] ?? null);
                    }
                }
                $trace = json_encode($error->getTrace(), JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('SECRET-BODY', $trace);
                self::assertStringNotContainsString('sig1=:', $trace);
            }
        }
    }

    #[Test]
    public function it_refuses_verified_known_data_missing_required_nested_identities(): void
    {
        $data = P::meterCharge();
        self::assertIsArray($data['package']);
        unset($data['walletId'], $data['package']['id']);
        $this->expectException(UnverifiableWebhookException::class);
        $this->verifier()->verifyAndParse($this->delivery(self::body('meterCharge.succeeded', $data)));
    }

    #[Test]
    public function it_checks_the_actual_method_path_and_query_captured_by_the_receiver_example(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/webhook-receiver/receive.php';
        $delivery = $this->delivery(self::body('webhook_endpoint.test', ['message' => 'hi']));
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hooks/anis?store=7&lang=ar', 'HTTP_HOST' => 'forged.example'];
        $captured = \receivedWebhook('https://pos.example.ly', $server, $delivery->headers, $delivery->body);
        self::assertSame(self::URL, $captured->targetUri);
        self::assertSame(WebhookEventType::EndpointTest, $this->verifier()->verifyAndParse($captured)->type);
        foreach ([
            ['GET', '/hooks/anis?store=7&lang=ar', WebhookVerificationFailure::MethodUnexpected],
            ['POST', '/wrong?store=7&lang=ar', WebhookVerificationFailure::TargetUriMismatch],
            ['POST', '/hooks/anis?store=8&lang=ar', WebhookVerificationFailure::TargetUriMismatch],
        ] as [$method, $target, $failure]) {
            $actual = \receivedWebhook('https://pos.example.ly', ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $target], $delivery->headers, $delivery->body);
            try {
                $this->verifier()->verifyAndParse($actual);
                self::fail('The receiver must refuse a different actual method or target.');
            } catch (UnverifiableWebhookException $error) {
                self::assertSame($failure, $error->failure());
            }
        }
    }

    #[Test]
    public function it_reports_malformed_published_key_timestamps_through_the_http_source(): void
    {
        foreach (['WH-061-not-before-missing-time', 'WH-068-retired-at-missing-offset'] as $id) {
            $vector = JsonFixture::readObject(dirname(__DIR__) . '/vectors/webhook/' . $id . '.json');
            $keyDocument = JsonFixture::object($vector['signingKeys'] ?? null);
            $http = new FakeHttpClient(json_encode($keyDocument, JSON_THROW_ON_ERROR));
            $source = new HttpSigningKeySource($http, new HttpFactory(), 'https://partners.example');
            $receiver = JsonFixture::object($vector['receiver'] ?? null);
            $request = JsonFixture::object($vector['request'] ?? null);
            $headers = [];
            foreach (JsonFixture::object($request['headers'] ?? null) as $name => $value) {
                self::assertIsString($value);
                $headers[$name] = $value;
            }
            $webhook = new VerifiableWebhook(
                JsonFixture::string($request, 'method'),
                JsonFixture::string($request, 'targetUri'),
                $headers,
                JsonFixture::base64(JsonFixture::string($request, 'bodyBase64')),
            );
            try {
                (new PartnerWebhookVerifier(
                    $source,
                    JsonFixture::string($receiver, 'configuredUrl'),
                    JsonFixture::string($receiver, 'applicationId'),
                    new FrozenClock(JsonFixture::integer($vector, 'verifyAt')),
                ))
                    ->verify($webhook);
                self::fail('Malformed key timestamps must not escape as document parsing errors or authenticate a delivery.');
            } catch (UnverifiableWebhookException $error) {
                self::assertSame(WebhookVerificationFailure::KeyRejected, $error->failure());
                self::assertSame(1, $http->calls);
            }
        }
    }
}
