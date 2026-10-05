<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\Order;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\RevealedCredentialCollection;
use Anis\Partners\Signing\SignedRequestHeaders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretRedactionTest extends TestCase
{
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
