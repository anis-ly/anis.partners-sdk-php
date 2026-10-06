<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Observability\AnisPartnersTelemetry;
use Anis\Partners\Observability\Log;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Verifies Anis response signatures and refuses any response that fails the published rules. */
final class PartnerResponseVerifier
{
    /**
     * Supplies response keys and a clock so key-cache age and signature freshness share one time source.
     *
     */
    public function __construct(
        private readonly SigningKeySource $keys,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $clientName = 'default',
    ) {}

    /** Throws a reason-only refusal unless status, headers, digest, time, key, and signature all agree. */
    public function verify(#[\SensitiveParameter] VerifiableResponse $response): void
    {
        $signatureInput = $response->header('Signature-Input');
        $signature = $response->header('Signature');
        if ($signatureInput === null || $signatureInput === '' || $signature === null || $signature === '') {
            $this->fail(ResponseVerificationFailure::SignatureMissing);
        }
        $parsed = SignatureInputParser::tryParse($signatureInput);
        if ($parsed === null) {
            $this->fail(ResponseVerificationFailure::SignatureMalformed);
        }
        $signatureLabelEnd = strpos($signature, '=');
        if ($parsed['label'] !== PartnerResponseSignatureBase::LABEL || $signatureLabelEnd === false || substr($signature, 0, $signatureLabelEnd) !== PartnerResponseSignatureBase::LABEL) {
            $this->fail(ResponseVerificationFailure::LabelUnexpected);
        }
        if ($parsed['algorithm'] !== null && $parsed['algorithm'] !== PartnerResponseSignatureBase::ALGORITHM) {
            $this->fail(ResponseVerificationFailure::AlgorithmNotSupported);
        }

        $contentDigest = $response->header('Content-Digest');
        $computedDigest = 'sha-256=:' . base64_encode(hash('sha256', $response->body, true)) . ':';
        // The signature covers the digest header, so compare it to the received body before cryptographic checks.
        $contentEncoding = $response->header('Content-Encoding');
        if (($contentEncoding !== null && trim($contentEncoding) !== '' && strtolower(trim($contentEncoding)) !== 'identity')
            || $contentDigest === null || !hash_equals($computedDigest, $contentDigest)) {
            $this->fail(ResponseVerificationFailure::ContentDigestMismatch);
        }
        $requestId = $response->header('X-Request-Id');
        if ($requestId === null || $requestId === '') {
            $this->fail(ResponseVerificationFailure::CoveredComponentsMismatch);
        }
        $components = PartnerResponseSignatureBase::components(
            $response->status,
            $contentDigest,
            $requestId,
            $response->requestSignatureInput,
            $response->header('Location'),
            $response->header('Retry-After'),
            $response->header('Idempotency-Replayed'),
            $response->header('Cache-Control'),
        );
        // Rebuilding from received headers prevents a valid signature from omitting a required profile component.
        $expected = array_map(static fn(array $component): string => str_replace('"', '', $component['identifier']), $components);
        if ($expected !== $parsed['identifiers']) {
            $this->fail(ResponseVerificationFailure::CoveredComponentsMismatch);
        }
        $hasRequestBinding = in_array('signature-input;req', $parsed['identifiers'], true);
        if ($hasRequestBinding && ($response->requestSignatureInput === null || $response->requestSignatureInput === '')) {
            $this->fail(ResponseVerificationFailure::CoveredComponentsMismatch);
        }

        $age = $this->clock->now()->getTimestamp() - $parsed['created'];
        if (abs($age) > PartnerResponseSignatureBase::MAX_AGE_SECONDS) {
            $this->fail(ResponseVerificationFailure::CreatedOutOfWindow);
        }

        $document = $this->keys->get();
        $key = $this->resolve($document, $parsed['keyId']);
        if ($key === null) {
            Log::write($this->logger ?? new NullLogger(), 'warning', 'Anis used an unknown signing key; refreshing once for key {key_id}', 1006, ['key_id' => $parsed['keyId']]);
            $document = $this->keys->refresh();
            $key = $this->resolve($document, $parsed['keyId']);
        }
        foreach ($document->keys as $candidate) {
            if ($candidate->hasPrivateMember) {
                $this->fail(ResponseVerificationFailure::KeyRejected);
            }
        }
        if ($key === null) {
            $this->fail(ResponseVerificationFailure::UnknownKey);
        }

        $signatureBytes = $this->signatureBytes($signature);
        if ($signatureBytes === null || strlen($signatureBytes) !== 64) {
            $this->fail(ResponseVerificationFailure::SignatureMalformed);
        }
        $x = $this->coordinate($key->x);
        $y = $this->coordinate($key->y);
        if ($x === null || $y === null) {
            $this->fail(ResponseVerificationFailure::KeyRejected);
        }
        $base = PartnerResponseSignatureBase::build($components, $parsed['created'], $parsed['keyId']);
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            self::clearOpenSslErrors();
            $this->fail(ResponseVerificationFailure::KeyRejected);
        }
        $der = EcdsaSignatureFormat::p1363ToDer($signatureBytes);
        $verified = openssl_verify($base, $der, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            self::clearOpenSslErrors();
            $this->fail(ResponseVerificationFailure::SignatureInvalid);
        }
    }

    private function resolve(SigningKeySet $document, string $keyId): ?PartnerJwk
    {
        foreach ($document->keys as $key) {
            if ($key->kid === $keyId && $key->x !== null && $key->x !== '' && $key->y !== null && $key->y !== '') {
                return $key;
            }
        }

        return null;
    }

    private function coordinate(#[\SensitiveParameter] ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = Base64Url::decode($value);

        return $decoded !== null && strlen($decoded) === 32 ? $decoded : null;
    }

    private function signatureBytes(#[\SensitiveParameter] string $signature): ?string
    {
        if (preg_match('/\Asig1=:([A-Za-z0-9+\/=]*):\z/D', $signature, $matches) !== 1) {
            return null;
        }
        $decoded = base64_decode($matches[1], true);

        return $decoded === false || base64_encode($decoded) !== $matches[1] ? null : $decoded;
    }

    private function fail(ResponseVerificationFailure $failure): never
    {
        AnisPartnersTelemetry::verificationFailure($failure->value, $this->clientName);
        Log::write($this->logger ?? new NullLogger(), 'error', 'Anis response discarded because verification failed: {failure}', 1003, ['failure' => $failure->value]);
        throw new UnverifiableResponseException($failure);
    }

    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
