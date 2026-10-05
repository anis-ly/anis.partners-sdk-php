<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Signing;

use Anis\Partners\Signing\EcdsaSignatureFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EcdsaSignatureFormatTest extends TestCase
{
    #[Test]
    public function it_left_pads_a_31_byte_r_and_strips_a_33_byte_s_sign_byte(): void
    {
        $r = str_repeat("\x01", 31);
        $s = "\0" . "\x80" . str_repeat("\x02", 31);
        $content = "\x02\x1f" . $r . "\x02\x21" . $s;
        $p1363 = EcdsaSignatureFormat::derToP1363("\x30" . chr(strlen($content)) . $content);

        self::assertSame(64, strlen($p1363));
        self::assertSame("\0" . $r, substr($p1363, 0, 32));
        self::assertSame(substr($s, 1), substr($p1363, 32, 32));
    }

    #[Test]
    public function it_round_trips_two_hundred_fresh_p256_signatures(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertArrayHasKey('key', $details);
        for ($i = 0; $i < 200; $i++) {
            $signature = '';
            self::assertTrue(openssl_sign('round-trip-' . $i, $signature, $key, OPENSSL_ALGO_SHA256));
            if (!is_string($signature)) {
                throw new \RuntimeException('OpenSSL returned an invalid DER signature.');
            }
            $p1363 = EcdsaSignatureFormat::derToP1363($signature);
            self::assertSame(64, strlen($p1363));
            if (!is_string($details['key'] ?? null)) {
                throw new \RuntimeException('OpenSSL did not expose a public key.');
            }
            self::assertSame(1, openssl_verify('round-trip-' . $i, EcdsaSignatureFormat::p1363ToDer($p1363), $details['key'], OPENSSL_ALGO_SHA256));
        }
    }

    #[Test]
    public function it_rejects_trailing_der_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EcdsaSignatureFormat::derToP1363("\x30\x06\x02\x01\x01\x02\x01\x01x");
    }

    #[Test]
    public function it_rejects_a_wrong_der_tag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EcdsaSignatureFormat::derToP1363("\x31\x06\x02\x01\x01\x02\x01\x01");
    }

    #[Test]
    public function it_rejects_a_der_length_that_exceeds_the_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EcdsaSignatureFormat::derToP1363("\x30\x82\xff\xff\x02\x01\x01");
    }

    #[Test]
    public function it_rejects_negative_der_integers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EcdsaSignatureFormat::derToP1363("\x30\x06\x02\x01\x80\x02\x01\x01");
    }

    #[Test]
    public function it_rejects_zero_der_integers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EcdsaSignatureFormat::derToP1363("\x30\x06\x02\x01\x00\x02\x01\x01");
    }

    #[Test]
    public function it_rejects_a_65_byte_p1363_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EcdsaSignatureFormat::p1363ToDer(str_repeat("\0", 65));
    }
}
