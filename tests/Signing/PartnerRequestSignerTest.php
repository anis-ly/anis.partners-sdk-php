<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Signing;

use Anis\Partners\Signing\PartnerRequestSigner;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureInputs;
use Anis\Partners\Signing\SignatureProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartnerRequestSignerTest extends TestCase
{
    #[Test]
    public function it_wraps_a_der_signature_without_leaking_the_base_or_signature(): void
    {
        $signer = new class implements RequestSigner {
            public function keyId(): string
            {
                return '3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43';
            }
            public function sign(string $data): string
            {
                return str_repeat('S', 71);
            }
        };
        $baseMarker = 'private-base-marker';
        $inputs = new SignatureInputs('GET', 'partners.anis.ly', $baseMarker, '', '2026-09-19T08:00:00Z');
        try {
            (new PartnerRequestSigner($signer))->sign(SignatureProfile::SafeRead, $inputs, 10, 20);
            self::fail('Expected the malformed signature to be refused.');
        } catch (RequestSigningException $error) {
            self::assertStringNotContainsString($baseMarker, $error->getMessage());
            self::assertStringNotContainsString(str_repeat('S', 71), $error->getMessage());
            self::assertStringNotContainsString('3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43', $error->getMessage());
            self::assertStringContainsString('nothing was sent', $error->getMessage());
            self::assertStringContainsString('almost certainly DER', $error->getMessage());
        }
    }

    #[Test]
    public function it_wraps_signer_failures(): void
    {
        $signerFailure = new \RuntimeException('signer-private-failure-marker');
        $signer = new class ($signerFailure) implements RequestSigner {
            public function __construct(private readonly \Throwable $failure) {}

            public function keyId(): string
            {
                return '3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43';
            }
            public function sign(string $data): string
            {
                throw $this->failure;
            }
        };

        try {
            (new PartnerRequestSigner($signer))->sign(SignatureProfile::SafeRead, self::inputs(), 10, 20);
            self::fail('The signer failure should be wrapped.');
        } catch (RequestSigningException $error) {
            self::assertSame('The request could not be signed and nothing was sent.', $error->getMessage());
            self::assertSame($signerFailure, $error->getPrevious());
            self::assertStringNotContainsString('private-base-marker', $error->getMessage());
            self::assertStringNotContainsString('signer-private-failure-marker', $error->getMessage());
            self::assertStringNotContainsString('3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43', $error->getMessage());
        }
    }

    #[Test]
    public function it_refuses_a_nonce_on_a_safe_read(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PartnerRequestSigner(self::signer()))->sign(SignatureProfile::SafeRead, self::inputs('nonce'), 10, 20);
    }

    #[Test]
    public function it_refuses_a_missing_nonce_on_a_mutation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PartnerRequestSigner(self::signer()))->sign(SignatureProfile::BodylessNonceMutation, self::inputs(), 10, 20);
    }

    #[Test]
    public function it_refuses_lifetimes_over_300_seconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PartnerRequestSigner(self::signer()))->sign(SignatureProfile::SafeRead, self::inputs(), 10, 311);
    }

    #[Test]
    public function it_uses_canonical_request_values_in_the_base_and_returned_headers(): void
    {
        $inputs = new SignatureInputs(
            'post',
            'PaRtNeRs.AnIs.Ly',
            '/v1/orders',
            '',
            '2026-09-19T08:00:00Z',
            'sha-256=:digest:',
            'fresh-nonce',
            '3F2A9C14-8D6E-4B21-9F07-5C8AB2D61E43',
        );
        $signed = (new PartnerRequestSigner(self::signer()))->sign(SignatureProfile::OrderMutation, $inputs, 10, 20);

        self::assertSame('POST', $inputs->method);
        self::assertSame('partners.anis.ly', $inputs->authority);
        self::assertSame('3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43', $inputs->idempotencyKey);
        self::assertStringContainsString('"@method": POST', $signed->signatureBase);
        self::assertStringContainsString('"@authority": partners.anis.ly', $signed->signatureBase);
        self::assertStringContainsString('"idempotency-key": 3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43', $signed->signatureBase);
        self::assertSame('3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43', $signed->idempotencyKey);
    }

    private static function signer(): RequestSigner
    {
        return new class implements RequestSigner {
            public function keyId(): string
            {
                return '3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43';
            }
            public function sign(string $data): string
            {
                return str_repeat("\0", 64);
            }
        };
    }

    private static function inputs(?string $nonce = null): SignatureInputs
    {
        return new SignatureInputs('GET', 'partners.anis.ly', '/v1/profile', '', '2026-09-19T08:00:00Z', nonce: $nonce);
    }
}
