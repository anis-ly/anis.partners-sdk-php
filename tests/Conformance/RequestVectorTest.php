<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Conformance;

use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Anis\Partners\Signing\PartnerRequestSigner;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Signing\SignatureInputs;
use Anis\Partners\Signing\SignatureProfile;
use Anis\Partners\Tests\Support\JsonFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestVectorTest extends TestCase
{
    #[Test]
    public function it_counts_all_request_vectors(): void
    {
        self::assertCount(9, self::vectorFiles());
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
    public function it_reproduces_a_request_vector_and_signs_with_p1363(string $file): void
    {
        $vector = JsonFixture::readObject($file);
        $request = JsonFixture::object($vector['request'] ?? null);
        $key = JsonFixture::object($vector['key'] ?? null);
        $expected = JsonFixture::object($vector['expected'] ?? null);
        $bodyBase64 = JsonFixture::nullableString($request, 'bodyBase64');
        $body = $bodyBase64 === null ? null : base64_decode($bodyBase64, true);
        if ($body === false) {
            throw new \UnexpectedValueException('The request vector body is not valid base64.');
        }
        $inputs = new SignatureInputs(
            JsonFixture::string($request, 'method'),
            JsonFixture::string($request, 'authorityAsGiven'),
            JsonFixture::string($request, 'path'),
            JsonFixture::string($request, 'canonicalQuery'),
            JsonFixture::string($request, 'anisDate'),
            $body === null ? null : ContentDigest::of($body),
            JsonFixture::nullableString($request, 'nonce'),
            JsonFixture::nullableString($request, 'idempotencyKey'),
        );
        $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(JsonFixture::string($key, 'privateKeyPkcs8Base64'), 64, "\n") . "-----END PRIVATE KEY-----\n";
        $signer = PemP256Signer::fromPem($pem)->forKey(JsonFixture::string($key, 'keyId'));
        $private = openssl_pkey_get_private($pem);
        if ($private === false) {
            throw new \RuntimeException('The request vector key could not be loaded.');
        }
        $details = openssl_pkey_get_details($private);
        if (!is_array($details) || !is_string($details['key'] ?? null)) {
            throw new \RuntimeException('The request vector public key could not be loaded.');
        }
        $signatureVector = JsonFixture::object($vector['signature'] ?? null);
        $signed = (new PartnerRequestSigner($signer))->sign(
            SignatureProfile::from(JsonFixture::string($vector, 'profile')),
            $inputs,
            JsonFixture::integer($signatureVector, 'created'),
            JsonFixture::integer($signatureVector, 'expires'),
        );

        self::assertSame(JsonFixture::string($expected, 'signatureBaseUtf8'), $signed->signatureBase);
        self::assertSame(JsonFixture::string($expected, 'signatureInput'), $signed->signatureInput);
        self::assertSame(JsonFixture::nullableString($expected, 'contentDigest'), $signed->contentDigest);
        $parts = explode(':', $signed->signature);
        $signature = base64_decode($parts[1] ?? '', true);
        if ($signature === false) {
            throw new \RuntimeException('The generated signature is not valid base64.');
        }
        self::assertSame(64, strlen($signature));
        self::assertSame(1, openssl_verify(
            $signed->signatureBase,
            EcdsaSignatureFormat::p1363ToDer($signature),
            $details['key'],
            OPENSSL_ALGO_SHA256,
        ));
    }

    /** @return list<string> */
    private static function vectorFiles(): array
    {
        $files = glob(dirname(__DIR__) . '/vectors/request/RQ-*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
