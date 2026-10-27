<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Models\SetWebhookEndpointRequest;
use Anis\Partners\Models\WebhookEndpointStatus;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookEndpointOperationsTest extends TestCase
{
    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    private static function wire(int $status, string $body): SignedFakeWire
    {
        $wire = new SignedFakeWire();
        $wire->status = $status;
        $wire->body = $body;

        return $wire;
    }

    /** @return iterable<string, array{string, string, ?string, ?int}> */
    public static function states(): iterable
    {
        yield 'notConfigured' => ['{"url":null,"status":"notConfigured","generation":null}', 'notConfigured', null, null];
        yield 'pendingVerification' => ['{"url":"https://pos.example.ly/hooks/anis","status":"pendingVerification","generation":7}', 'pendingVerification', 'https://pos.example.ly/hooks/anis', 7];
        yield 'verified' => ['{"url":"https://pos.example.ly/hooks/anis","status":"verified","generation":7}', 'verified', 'https://pos.example.ly/hooks/anis', 7];
        yield 'disabled' => ['{"url":"https://pos.example.ly/hooks/anis","status":"disabled","generation":8}', 'disabled', 'https://pos.example.ly/hooks/anis', 8];
    }

    #[Test]
    #[DataProvider('states')]
    public function it_reads_each_endpoint_state_and_keeps_explicit_nulls(string $body, string $status, ?string $url, ?int $generation): void
    {
        $wire = self::wire(200, $body);
        $wire->signResponses = false;
        $endpoint = self::client($wire)->webhookEndpoint()->get();

        self::assertSame(WebhookEndpointStatus::from($status), $endpoint->status);
        self::assertSame($url, $endpoint->url);
        self::assertSame($generation, $endpoint->generation);
        self::assertSame(['url' => $url, 'status' => $status, 'generation' => $generation], $endpoint->jsonSerialize());
        self::assertSame('GET', $wire->requests[0]->getMethod());
        self::assertCount(1, $wire->requests);
        self::assertSame('', $wire->requests[0]->getHeaderLine('Idempotency-Key'));
    }

    #[Test]
    public function it_puts_the_exact_url_json_as_a_signed_change(): void
    {
        $wire = self::wire(200, '{"url":"https://pos.example.ly/hooks/anis","status":"pendingVerification","generation":7}');
        $endpoint = self::client($wire)->webhookEndpoint()->set(new SetWebhookEndpointRequest('https://pos.example.ly/hooks/anis'));

        $sent = $wire->requests[0];
        $body = (string) $sent->getBody();
        self::assertSame('PUT', $sent->getMethod());
        self::assertSame('{"url":"https://pos.example.ly/hooks/anis"}', $body);
        self::assertSame(ContentDigest::of($body), $sent->getHeaderLine('Content-Digest'));
        self::assertNotSame('', $sent->getHeaderLine('Nonce'));
        self::assertSame('', $sent->getHeaderLine('Idempotency-Key'));
        self::assertSame(WebhookEndpointStatus::PendingVerification, $endpoint->status);
    }

    #[Test]
    public function it_signs_the_exact_empty_object_for_a_test_notification(): void
    {
        $wire = self::wire(202, '{"id":"22222222-2222-4222-8222-222222222222","type":"webhook_endpoint.test","status":"pending","queuedAt":"2026-10-20T08:00:00Z"}');
        $accepted = self::client($wire)->webhookEndpoint()->test();

        $sent = $wire->requests[0];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('/v1/webhook-endpoint/test', $sent->getUri()->getPath());
        self::assertSame('{}', (string) $sent->getBody());
        self::assertSame(ContentDigest::of('{}'), $sent->getHeaderLine('Content-Digest'));
        self::assertSame('', $sent->getHeaderLine('Idempotency-Key'));
        self::assertSame('webhook_endpoint.test', $accepted->type);
        self::assertSame('pending', $accepted->status->value);
        self::assertSame('2026-10-20T08:00:00+00:00', $accepted->queuedAt->format('c'));
    }

    #[Test]
    public function it_surfaces_signed_refusals_without_replaying_them(): void
    {
        foreach ([['unverified', 409, 'webhook_endpoint_unverified'], ['invalid', 422, 'webhook_endpoint_invalid']] as [, $status, $code]) {
            $wire = self::wire($status, json_encode(['status' => $status, 'code' => $code], JSON_THROW_ON_ERROR));
            try {
                self::client($wire)->webhookEndpoint()->set(new SetWebhookEndpointRequest('http://pos.example.ly'));
                self::fail('A refusal must surface.');
            } catch (AnisApiException $error) {
                self::assertSame($code, $error->rawCode);
                self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'PUT'));
            }
        }
    }

    #[Test]
    public function it_discards_an_unsigned_or_tampered_answer_to_a_signed_change(): void
    {
        $unsigned = self::wire(200, '{"url":null,"status":"notConfigured","generation":null}');
        $unsigned->signResponses = false;
        $this->expectException(\Anis\Partners\Verification\UnverifiableResponseException::class);
        self::client($unsigned)->webhookEndpoint()->set(new SetWebhookEndpointRequest('https://pos.example.ly/hooks/anis'));
    }
}
