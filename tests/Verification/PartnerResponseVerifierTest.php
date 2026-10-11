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
use PHPUnit\Framework\Attributes\DataProvider;
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
            $verifier->verify(new VerifiableResponse($response->status, $headers, $response->body, $response->requestSignatureInput, $response->requestSignature));
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
            $verifier->verify(new VerifiableResponse($response->status, $headers, $response->body, $response->requestSignatureInput, $response->requestSignature));
            self::fail('The entire Signature field must match the supported grammar.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame(ResponseVerificationFailure::SignatureMalformed, $error->failure());
        }
    }

    /** @return iterable<string, array{string, int, bool, ?ResponseVerificationFailure, 4?: int}> */
    public static function lifecycleCases(): iterable
    {
        yield 'revoked overrides active' => ['active', 0, true, ResponseVerificationFailure::KeyCompromised];
        yield 'revoked absent from keys' => ['absent', 0, true, ResponseVerificationFailure::KeyCompromised];
        yield 'retired before creation' => ['retired', -1, false, ResponseVerificationFailure::KeyRetired];
        yield 'retired at creation' => ['retired', 0, false, null];
        yield 'retired after creation' => ['retired', 1, false, null];
        yield 'next after creation' => ['next', 1, false, ResponseVerificationFailure::KeyNotYetValid];
        yield 'next at creation' => ['next', 0, false, null];
        yield 'next before creation' => ['next', -1, false, null];
        yield 'next half a second after creation' => ['next', 0, false, ResponseVerificationFailure::KeyNotYetValid, 500000];
        yield 'next one microsecond after creation' => ['next', 0, false, ResponseVerificationFailure::KeyNotYetValid, 1];
        yield 'next one microsecond before creation' => ['next', -1, false, null, 999999];
        yield 'retired half a second after creation' => ['retired', 0, false, null, 500000];
        yield 'retired one microsecond after creation' => ['retired', 0, false, null, 1];
        yield 'retired one microsecond before creation' => ['retired', -1, false, ResponseVerificationFailure::KeyRetired, 999999];
    }

    #[Test]
    #[DataProvider('lifecycleCases')]
    public function it_enforces_signing_key_lifecycle(string $status, int $offset, bool $revoked, ?ResponseVerificationFailure $failure, int $microseconds = 0): void
    {
        $vector = self::vector();
        $document = JsonFixture::object($vector['signingKeys'] ?? null);
        $keys = JsonFixture::objectList($document, 'keys');
        $created = JsonFixture::integer($vector, 'verifyAt');
        $boundary = gmdate('Y-m-d\TH:i:s', $created + $offset) . sprintf('.%06dZ', $microseconds);
        $keys[0]['status'] = $status === 'absent' ? 'active' : $status;
        $keys[0]['notBefore'] = $status === 'next' ? $boundary : '2026-01-01T00:00:00Z';
        if ($status === 'retired') {
            $keys[0]['retiredAt'] = $boundary;
        }
        $document['keys'] = $status === 'absent' ? [] : $keys;
        $document['revoked'] = $revoked ? [['kid' => 'partner-response-signing/v1-active', 'compromisedAt' => $boundary]] : [];
        $source = new StaticSigningKeySource(SigningKeySet::fromJson(JsonFixture::encode($document)));
        $verifier = new PartnerResponseVerifier($source, new FrozenClock($created));
        if ($failure === null) {
            $verifier->verify(self::response($vector));
            self::assertSame(0, $source->refreshCalls);
            return;
        }
        try {
            $verifier->verify(self::response($vector));
            self::fail('The key lifecycle must reject this answer.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame($failure, $error->failure());
            self::assertSame(0, $source->refreshCalls);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function profileTags(): iterable
    {
        yield 'missing' => [null];
        yield 'other profile' => ['other'];
    }

    #[Test]
    #[DataProvider('profileTags')]
    public function it_requires_the_response_v2_tag(?string $tag): void
    {
        $vector = self::vector();
        $response = self::response($vector);
        $headers = $response->headers;
        $headers['signature-input'] = str_replace(';tag="anis-response-v2"', $tag === null ? '' : ';tag="' . $tag . '"', $headers['signature-input']);
        $verifier = new PartnerResponseVerifier(new StaticSigningKeySource(new SigningKeySet([])), new FrozenClock(JsonFixture::integer($vector, 'verifyAt')));
        try {
            $verifier->verify(new VerifiableResponse(200, $headers, $response->body, $response->requestSignatureInput, $response->requestSignature));
            self::fail('Only the response v2 profile is accepted.');
        } catch (UnverifiableResponseException $error) {
            self::assertSame(ResponseVerificationFailure::ProfileUnexpected, $error->failure());
        }
    }

    #[Test]
    public function it_binds_the_raw_request_signature_and_requires_both_components(): void
    {
        $vector = self::vector();
        $response = self::response($vector);
        $source = new StaticSigningKeySource(SigningKeySet::fromJson(JsonFixture::encode(JsonFixture::object($vector['signingKeys'] ?? null))));
        $verifier = new PartnerResponseVerifier($source, new FrozenClock(JsonFixture::integer($vector, 'verifyAt')));
        foreach ([false, true] as $omitComponent) {
            $headers = $response->headers;
            if ($omitComponent) {
                $headers['signature-input'] = str_replace(' "signature";req', '', $headers['signature-input']);
            }
            try {
                $verifier->verify(new VerifiableResponse(200, $headers, $response->body, $response->requestSignatureInput, 'sig1=:different:'));
                self::fail('The request signature must be authenticated.');
            } catch (UnverifiableResponseException $error) {
                self::assertSame($omitComponent ? ResponseVerificationFailure::CoveredComponentsMismatch : ResponseVerificationFailure::SignatureInvalid, $error->failure());
            }
        }
    }

    /** @return array<string, mixed> */
    private static function vector(): array
    {
        $wire = new \Anis\Partners\Tests\Support\SignedFakeWire();
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://partners.example/v1/orders/test', ['Signature-Input' => 'sig1=("@method");created=1', 'Signature' => 'sig1=:request:']);
        $response = $wire->sendRequest($request);
        $document = $wire->sendRequest(new \GuzzleHttp\Psr7\Request('GET', 'https://partners.example/.well-known/partner-signing-keys.json'));
        $parsed = \Anis\Partners\Verification\SignatureInputParser::tryParse($response->getHeaderLine('Signature-Input'));
        if ($parsed === null) {
            throw new \UnexpectedValueException('The signed test fixture is malformed.');
        }
        return ['verifyAt' => $parsed['created'], 'signingKeys' => json_decode((string) $document->getBody(), true, 512, JSON_THROW_ON_ERROR),
            'request' => ['signatureInput' => $request->getHeaderLine('Signature-Input'), 'signature' => $request->getHeaderLine('Signature')],
            'response' => ['status' => 200, 'headers' => array_map(static fn(array $values): string => implode(', ', $values), $response->getHeaders()), 'bodyBase64' => base64_encode((string) $response->getBody())]];
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
            JsonFixture::nullableString($request, 'signature'),
        );
    }
}
