<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\Order;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\RevealedCredentialCollection;
use Anis\Partners\Signing\SignedRequestHeaders;
use Anis\Partners\Verification\VerifiableResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretRedactionTest extends TestCase
{
    #[Test]
    public function it_hides_a_malformed_nested_credential_from_exception_traces(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            try {
                Order::fromArray([
                    'operationId' => '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
                    'soldCards' => [[
                        'soldCardId' => '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
                        'voucher' => 'trace-malformed-voucher',
                        'revealedAt' => 17,
                    ]],
                ]);
                self::fail('The malformed credential timestamp must be rejected.');
            } catch (\Throwable $exception) {
                self::assertStringNotContainsString('trace-malformed-voucher', print_r($exception->getTrace(), true));
            }
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    #[Test]
    public function it_omits_raw_response_bytes_from_native_diagnostic_output(): void
    {
        $response = new VerifiableResponse(200, ['Content-Type' => 'application/json'], '{"voucher":"debug-response-voucher"}', null);

        ob_start();
        var_dump($response);
        $diagnostics = (string) ob_get_clean() . print_r($response, true);

        self::assertStringNotContainsString('debug-response-voucher', $diagnostics);
        self::assertStringNotContainsString('body', $diagnostics);
    }

    #[Test]
    public function it_redacts_credentials_nested_in_an_order_and_invoice_collection(): void
    {
        $credential = new RevealedCredential('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', 'serial-private', 'voucher-private');
        $order = new Order('9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', soldCards: [$credential]);
        $result = new OrderCompleted($order);
        $collection = new RevealedCredentialCollection([$credential]);

        ob_start();
        var_dump($result, $collection);
        $dump = (string) ob_get_clean();
        $printed = print_r([$result, $collection], true);

        self::assertStringContainsString('<redacted>', $dump . $printed);
        self::assertStringNotContainsString('voucher-private', $dump . $printed);
        self::assertStringNotContainsString('serial-private', $dump . $printed);
    }

    #[Test]
    public function it_keeps_credentials_available_for_partner_storage_and_redacts_debug_output(): void
    {
        $credential = new RevealedCredential('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', 'serial-private', 'voucher-private');

        $json = json_encode($credential, JSON_THROW_ON_ERROR);
        $diagnostics = var_export($credential, true) . (string) $credential;

        self::assertStringContainsString('voucher-private', $json);
        self::assertStringContainsString('voucher-private', serialize($credential));
        self::assertStringContainsString('voucher-private', $diagnostics);
        self::assertFalse($credential->voucher === '');
        self::assertTrue(isset($credential->voucher));
        self::assertSame('voucher-private', (clone $credential)->voucher);

        $order = new Order('9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', soldCards: [$credential]);
        self::assertEquals($order, unserialize(serialize($order), ['allowed_classes' => true]));
    }

    #[Test]
    public function it_redacts_signature_fields_from_native_inspection_and_string_conversion(): void
    {
        $headers = new SignedRequestHeaders('input-private', 'signature-private', '2026-10-05T00:00:00Z', null, 'nonce-private', null, 'base-private');
        ob_start();
        var_dump($headers);
        $dump = (string) ob_get_clean();
        $printed = print_r($headers, true) . (string) $headers;

        self::assertStringContainsString('<redacted>', $dump . $printed);
        self::assertStringNotContainsString('signature-private', $dump . $printed);
        self::assertStringNotContainsString('input-private', $dump . $printed);
        self::assertStringNotContainsString('nonce-private', $dump . $printed);
        self::assertStringNotContainsString('base-private', $dump . $printed);
    }
}
