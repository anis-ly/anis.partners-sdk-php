<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Enrollment;

use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Enrollment\EnrollmentKeyMismatchException;
use Anis\Partners\Enrollment\EnrollmentProof;
use Anis\Partners\Enrollment\KeyThumbprint;
use Anis\Partners\Enrollment\SafetyCode;
use Anis\Partners\Errors\EnrollmentRefusedException;
use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Models\EnrollmentKeyResult;
use Anis\Partners\Models\EnrollmentStatus;
use Anis\Partners\Signing\P256Signer;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Tests\Support\SignedFakeWire;
use Anis\Partners\Verification\PartnerJwk;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnrollmentClientTest extends TestCase
{
    #[Test]
    public function it_redacts_an_enrollment_token_from_native_client_inspection(): void
    {
        $factory = new HttpFactory();
        $client = EnrollmentClient::create(
            'https://partners.example',
            '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
            'enrollment-token-private',
            new \Anis\Partners\Tests\Support\FakeHttpClient(),
            $factory,
            $factory,
        );

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();

        self::assertStringContainsString('<redacted>', $dump);
        self::assertStringNotContainsString('enrollment-token-private', $dump);
    }

    private const INVITATION = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
    private const KEY = '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';

    #[Test]
    public function it_submits_a_key_then_proves_possession_on_verified_enrollment_routes(): void
    {
        [$key, $signer, $jwk] = self::newKey();
        $thumbprint = KeyThumbprint::compute($jwk);
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => json_encode(['keyId' => self::KEY, 'thumbprint' => $thumbprint, 'challenge' => 'one-use', 'challengeGeneration' => 4], JSON_THROW_ON_ERROR), 'headers' => ['X-Request-Id' => 'enroll-1']],
            ['status' => 200, 'body' => '{"state":"pendingApproval"}', 'headers' => ['X-Request-Id' => 'enroll-2']],
        ];
        $factory = new HttpFactory();
        $client = EnrollmentClient::create('https://partners.example', self::INVITATION, 'enrollment-secret', $wire, $factory, $factory);
        $privateJwk = new PartnerJwk($jwk->kty, $jwk->crv, $jwk->x, $jwk->y, d: 'private-material');
        $submitted = $client->submitKey(new EnrollmentKeyRequest($privateJwk, new \DateTimeImmutable('now'), new \DateTimeImmutable('+1 year')));
        $status = $client->prove($submitted, $signer);

        self::assertSame(SafetyCode::fromThumbprint($thumbprint), $submitted->safetyCode);
        self::assertSame('pendingApproval', $status->state);
        self::assertCount(3, $wire->requests);
        self::assertSame('Enrollment enrollment-secret', $wire->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('', $wire->requests[0]->getHeaderLine('Signature'));
        self::assertSame('', $wire->requests[2]->getHeaderLine('Signature'));
        self::assertStringContainsString('/keys', (string) $wire->requests[0]->getUri());
        self::assertStringContainsString('/proof', (string) $wire->requests[2]->getUri());
        self::assertStringNotContainsString('private-material', (string) $wire->requests[0]->getBody());
    }

    #[Test]
    public function it_stops_when_anis_reports_a_different_key_thumbprint(): void
    {
        [, , $jwk] = self::newKey();
        $wire = new SignedFakeWire();
        $wire->body = '{"keyId":"' . self::KEY . '","thumbprint":"wrong","challenge":"untrusted","challengeGeneration":1}';
        $factory = new HttpFactory();
        $client = EnrollmentClient::create('https://partners.example', self::INVITATION, 'token', $wire, $factory, $factory);

        $this->expectException(EnrollmentKeyMismatchException::class);
        $client->submitKey(new EnrollmentKeyRequest($jwk, new \DateTimeImmutable('now'), new \DateTimeImmutable('+1 year')));
    }

    #[Test]
    public function it_refuses_a_der_proof_before_sending_it(): void
    {
        $signer = new class implements P256Signer {
            public function sign(string $data): string
            {
                return str_repeat("\x01", 71);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        EnrollmentProof::signature('proof-bytes', $signer);
    }

    #[Test]
    public function it_refuses_a_different_curve_before_contacting_anis(): void
    {
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $client = EnrollmentClient::create('https://partners.example', self::INVITATION, 'token', $wire, $factory, $factory);
        $jwk = new PartnerJwk('EC', 'P-384', self::b64url(str_repeat("\x01", 32)), self::b64url(str_repeat("\x02", 32)));

        try {
            $client->submitKey(new EnrollmentKeyRequest($jwk, new \DateTimeImmutable('now'), new \DateTimeImmutable('+1 year')));
            self::fail('An enrollment key must be P-256.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $wire->requests);
        }
    }

    #[Test]
    public function it_requires_the_submitted_challenge_before_proving_possession(): void
    {
        [, $signer] = self::newKey();
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $client = EnrollmentClient::create('https://partners.example', self::INVITATION, 'token', $wire, $factory, $factory);

        $this->expectException(\InvalidArgumentException::class);
        $client->prove(new EnrollmentKeyResult(self::KEY, 'thumbprint', null, null, 1), $signer);
    }

    #[Test]
    public function it_surfaces_a_verified_enrollment_refusal_as_a_typed_error(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 409;
        $wire->body = '{"status":409,"code":"invitation_invalid"}';
        $factory = new HttpFactory();
        $client = EnrollmentClient::create('https://partners.example', self::INVITATION, 'token', $wire, $factory, $factory);

        $this->expectException(EnrollmentRefusedException::class);
        $client->get();
    }

    #[Test]
    public function it_treats_an_answer_without_a_thumbprint_as_a_key_mismatch(): void
    {
        [, , $jwk] = self::newKey();
        $wire = new SignedFakeWire();
        $wire->body = '{"keyId":"' . self::KEY . '","challenge":"untrusted","challengeGeneration":1}';
        $factory = new HttpFactory();
        $client = EnrollmentClient::create('https://partners.example', self::INVITATION, 'token', $wire, $factory, $factory);

        $this->expectException(EnrollmentKeyMismatchException::class);
        $client->submitKey(new EnrollmentKeyRequest($jwk, new \DateTimeImmutable('now'), new \DateTimeImmutable('+1 year')));
    }

    #[Test]
    public function it_ignores_a_private_jwk_member_when_fingerprinting_a_public_key(): void
    {
        [, , $jwk] = self::newKey();
        $private = new PartnerJwk($jwk->kty, $jwk->crv, $jwk->x, $jwk->y, d: 'private');

        self::assertSame(KeyThumbprint::compute($jwk), KeyThumbprint::compute($private));
    }

    #[Test]
    public function it_left_pads_a_public_coordinate_when_openssl_omits_a_leading_zero_byte(): void
    {
        $signer = PemP256Signer::fromPem(<<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgv0zByQQ74lHDBcl7
2vUP4nyLysRtO4FwZGSY0Qju37ShRANCAAQAwQt6F6p4LPIN0TrQ4pLQnGB3UTBO
sndTHwCBRtVTHB+HvgcnJ01cIQjL2Jl+0Te4kRY9pQH3f4T1oUwkMZVI
-----END PRIVATE KEY-----
PEM);

        $x = Base64Url::decode($signer->publicJwk()->x ?? '');

        self::assertNotNull($x);
        self::assertSame(32, strlen($x));
        self::assertSame("\0", $x[0]);
    }

    #[Test]
    public function it_reads_an_optional_key_end_date_in_enrollment_status(): void
    {
        $status = EnrollmentStatus::fromArray(['state' => 'active', 'keyExpiresAt' => '2027-03-31T00:00:00Z']);

        self::assertSame('2027-03-31T00:00:00+00:00', $status->keyExpiresAt?->format(DATE_ATOM));
        self::assertNull(EnrollmentStatus::fromArray(['state' => 'pendingProof'])->keyExpiresAt);
    }

    /** @return array{\OpenSSLAsymmetricKey, PemP256Signer, PartnerJwk} */
    private static function newKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem) || !is_string($pem)) {
            throw new \RuntimeException('Could not create an enrollment test key.');
        }
        $details = openssl_pkey_get_details($key);
        $ec = $details['ec'] ?? null;
        if (!is_array($ec) || !is_string($ec['x'] ?? null) || !is_string($ec['y'] ?? null)) {
            throw new \RuntimeException('The enrollment test key has no P-256 coordinates.');
        }
        $signer = PemP256Signer::fromPem($pem);

        return [$key, $signer, $signer->publicJwk()];
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
