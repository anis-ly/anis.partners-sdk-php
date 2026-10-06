<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Signing;

use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Anis\Partners\Signing\PemP256Signer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PemP256SignerTest extends TestCase
{
    #[Test]
    public function it_returns_a_64_byte_signature_that_verifies(): void
    {
        [$pem, $publicKey] = self::key('prime256v1');
        $signer = PemP256Signer::fromPem($pem);
        $signature = $signer->sign('data');

        self::assertSame(64, strlen($signature));
        self::assertSame(1, openssl_verify('data', EcdsaSignatureFormat::p1363ToDer($signature), $publicKey, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function it_accepts_whitespace_and_a_utf8_bom_before_pem_text(): void
    {
        [$pem] = self::key('prime256v1');

        self::assertSame(64, strlen(PemP256Signer::fromPem(" \n\xEF\xBB\xBF" . $pem . " \n")->sign('data')));
    }

    #[Test]
    public function it_refuses_a_p384_key_when_loading(): void
    {
        [$pem] = self::key('secp384r1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('P-256');
        PemP256Signer::fromPem($pem);
    }

    #[Test]
    public function it_exports_public_coordinates_as_32_bytes(): void
    {
        [$pem] = self::key('prime256v1');
        $jwk = PemP256Signer::fromPem($pem)->publicJwk();

        self::assertSame(32, strlen((string) Base64Url::decode((string) $jwk->x)));
        self::assertSame(32, strlen((string) Base64Url::decode((string) $jwk->y)));
    }

    /** @return array{string, string} */
    private static function key(string $curve): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        self::assertNotFalse($key);
        if (!openssl_pkey_export($key, $pem) || !is_string($pem)) {
            throw new \RuntimeException('OpenSSL could not export a test private key.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !is_string($details['key'] ?? null)) {
            throw new \RuntimeException('OpenSSL did not expose a test public key.');
        }

        return [$pem, $details['key']];
    }
}
