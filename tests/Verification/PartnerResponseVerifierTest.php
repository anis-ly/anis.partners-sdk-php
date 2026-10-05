<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Verification;

use Anis\Partners\Tests\Support\FrozenClock;
use Anis\Partners\Tests\Support\JsonFixture;
use Anis\Partners\Tests\Support\StaticSigningKeySource;
use Anis\Partners\Verification\PartnerJwk;
use Anis\Partners\Verification\PartnerResponseVerifier;
use Anis\Partners\Verification\ResponseVerificationFailure;
use Anis\Partners\Verification\SigningKeySet;
use Anis\Partners\Verification\UnverifiableResponseException;
use Anis\Partners\Verification\VerifiableResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartnerResponseVerifierTest extends TestCase
{
    #[Test]
    public function it_rejects_a_well_shaped_but_off_curve_key(): void
    {
        $vector = self::vector();
        $keys = [];
        $signingKeys = JsonFixture::object($vector['signingKeys'] ?? null);
        foreach (JsonFixture::objectList($signingKeys, 'keys') as $key) {
            if (JsonFixture::nullableString($key, 'kid') === 'partner-response-signing/v1-active') {
                $key['x'] = rtrim(strtr(base64_encode(str_repeat("\0", 32)), '+/', '-_'), '=');
                $key['y'] = rtrim(strtr(base64_encode(str_repeat("\0", 32)), '+/', '-_'), '=');
            }
            $keys[] = PartnerJwk::fromArray($key);
        }
        $source = new StaticSigningKeySource(new SigningKeySet($keys));
        $verifier = new PartnerResponseVerifier($source, new FrozenClock(JsonFixture::integer($vector, 'verifyAt')));

        try {
            $verifier->verify(self::response($vector));
            self::fail('The off-curve key should be rejected.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame(ResponseVerificationFailure::KeyRejected, $error->failure());
        }
    }

    #[Test]
    public function it_refreshes_exactly_once_for_an_unknown_key(): void
    {
        $vector = self::vector();
        $source = new StaticSigningKeySource(new SigningKeySet([]));
        $verifier = new PartnerResponseVerifier($source, new FrozenClock(JsonFixture::integer($vector, 'verifyAt')));

        try {
            $verifier->verify(self::response($vector));
            self::fail('The key is not present in either document.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame(ResponseVerificationFailure::UnknownKey, $error->failure());
        }
        self::assertSame(1, $source->getCalls);
        self::assertSame(1, $source->refreshCalls);
    }

    #[Test]
    public function it_discards_a_response_that_arrives_with_gzip_content_encoding(): void
    {
        $vector = self::vector();
        $response = self::response($vector);
        $headers = $response->headers;
        $headers['Content-Encoding'] = 'gzip';
        $source = new StaticSigningKeySource(SigningKeySet::fromJson(json_encode(JsonFixture::object($vector['signingKeys'] ?? null), JSON_THROW_ON_ERROR)));
        $verifier = new PartnerResponseVerifier($source, new FrozenClock(JsonFixture::integer($vector, 'verifyAt')));

        try {
            $verifier->verify(new VerifiableResponse($response->status, $headers, $response->body, $response->requestSignatureInput));
            self::fail('Encoded content cannot be compared to the bytes Anis signed.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame(ResponseVerificationFailure::ContentDigestMismatch, $error->failure());
        }
    }

    #[Test]
    public function it_rejects_junk_after_an_otherwise_valid_signature_field(): void
    {
        $vector = self::vector();
        $response = self::response($vector);
        $headers = $response->headers;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Signature') === 0) {
                $headers[$name] = $value . 'junk';
            }
        }
        $source = new StaticSigningKeySource(SigningKeySet::fromJson(json_encode(JsonFixture::object($vector['signingKeys'] ?? null), JSON_THROW_ON_ERROR)));
        $verifier = new PartnerResponseVerifier($source, new FrozenClock(JsonFixture::integer($vector, 'verifyAt')));

        try {
            $verifier->verify(new VerifiableResponse($response->status, $headers, $response->body, $response->requestSignatureInput));
            self::fail('The entire Signature field must match the supported grammar.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame(ResponseVerificationFailure::SignatureMalformed, $error->failure());
        }
    }

    /** @return array<string, mixed> */
    private static function vector(): array
    {
        return JsonFixture::readObject(dirname(__DIR__) . '/vectors/response/RS-001-safe-read-profile-200.json');
    }

    /** @param array<string, mixed> $vector */
    private static function response(array $vector): VerifiableResponse
    {
        $response = JsonFixture::object($vector['response'] ?? null);
        $request = JsonFixture::object($vector['request'] ?? null);

        return new VerifiableResponse(
            JsonFixture::integer($response, 'status'),
            JsonFixture::stringMap($response, 'headers'),
            JsonFixture::base64(JsonFixture::string($response, 'bodyBase64')),
            JsonFixture::nullableString($request, 'signatureInput'),
        );
    }
}
