<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Conformance;

use Anis\Partners\Enrollment\EnrollmentProof;
use Anis\Partners\Enrollment\KeyThumbprint;
use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Tests\Support\JsonFixture;
use Anis\Partners\Verification\PartnerJwk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnrollmentProofVectorTest extends TestCase
{
    #[Test]
    public function it_counts_all_enrollment_vectors(): void
    {
        self::assertCount(2, self::vectorFiles());
    }

    /** @return iterable<string, array{string}> */
    public static function vectors(): iterable
    {
        foreach (self::vectorFiles() as $file) {
            $vector = JsonFixture::readObject($file);
            yield JsonFixture::string($vector, 'id') => [$file];
        }
    }

    #[Test]
    #[DataProvider('vectors')]
    public function it_builds_and_verifies_an_enrollment_proof_vector(string $file): void
    {
        $vector = JsonFixture::readObject($file);
        $result = JsonFixture::object($vector['keySubmissionResult'] ?? null);
        $expected = JsonFixture::object($vector['expected'] ?? null);
        $key = JsonFixture::object($vector['key'] ?? null);
        $publicJwk = JsonFixture::object($key['publicJwk'] ?? null);
        $thumbprint = KeyThumbprint::compute(new PartnerJwk(
            JsonFixture::string($publicJwk, 'kty'),
            JsonFixture::string($publicJwk, 'crv'),
            JsonFixture::string($publicJwk, 'x'),
            JsonFixture::string($publicJwk, 'y'),
        ));
        self::assertSame(JsonFixture::string($result, 'thumbprint'), $thumbprint);
        $message = EnrollmentProof::message(
            JsonFixture::string($result, 'keyId'),
            JsonFixture::integer($result, 'challengeGeneration'),
            JsonFixture::string($result, 'challenge'),
            $thumbprint,
        );
        self::assertSame(JsonFixture::string($expected, 'proofMessageUtf8'), $message);
        self::assertSame(JsonFixture::string($expected, 'challengeHashHex'), hash('sha256', JsonFixture::string($result, 'challenge')));
        $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(JsonFixture::string($key, 'privateKeyPkcs8Base64'), 64, "\n") . "-----END PRIVATE KEY-----\n";
        $signer = PemP256Signer::fromPem($pem);
        $signature = Base64Url::decode(EnrollmentProof::signature($message, $signer));
        if ($signature === null) {
            throw new \RuntimeException('The generated proof signature is not valid base64url.');
        }
        self::assertSame(64, strlen($signature));
        $private = openssl_pkey_get_private($pem);
        if ($private === false) {
            throw new \RuntimeException('The enrollment vector key could not be loaded.');
        }
        $details = openssl_pkey_get_details($private);
        if (!is_array($details) || !is_string($details['key'] ?? null)) {
            throw new \RuntimeException('The enrollment vector public key could not be loaded.');
        }
        self::assertSame(1, openssl_verify($message, EcdsaSignatureFormat::p1363ToDer($signature), $details['key'], OPENSSL_ALGO_SHA256));
        $example = Base64Url::decode(JsonFixture::string($vector, 'exampleSignature'));
        if ($example === null) {
            throw new \RuntimeException('The enrollment example signature is not valid base64url.');
        }
        self::assertSame(1, openssl_verify($message, EcdsaSignatureFormat::p1363ToDer($example), $details['key'], OPENSSL_ALGO_SHA256));
    }

    /** @return list<string> */
    private static function vectorFiles(): array
    {
        $files = glob(dirname(__DIR__) . '/vectors/enrollment/EP-*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
