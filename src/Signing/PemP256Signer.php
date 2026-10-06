<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Verification\PartnerJwk;

/** Signs Partner requests with a PEM-held NIST P-256 private key. */
final class PemP256Signer implements P256Signer
{
    private function __construct(private readonly \OpenSSLAsymmetricKey $key)
    {
        $details = openssl_pkey_get_details($key);
        $ec = is_array($details) ? ($details['ec'] ?? null) : null;
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || !is_array($ec) || ($ec['curve_name'] ?? null) !== 'prime256v1') {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The Anis Partner API accepts NIST P-256 keys only; other curves cannot verify.');
        }
    }

    /** Loads a private key and refuses unsupported curves before any request is prepared. */
    public static function fromPem(#[\SensitiveParameter] string $pem): self
    {
        $pem = trim($pem);
        if (str_starts_with($pem, "\xEF\xBB\xBF")) {
            $pem = ltrim(substr($pem, 3));
        }
        if (!str_starts_with($pem, '-----BEGIN ')) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The private key must be PEM text, not a path or URI.');
        }
        if (str_contains($pem, 'ENCRYPTED PRIVATE KEY') || str_contains($pem, 'Proc-Type: 4,ENCRYPTED')) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Encrypted PEM private keys are not supported.');
        }
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            self::clearOpenSslErrors();
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The PEM does not contain a readable EC private key.');
        }

        return new self($key);
    }

    /** Loads a PEM private key from disk and applies the same P-256 check as fromPem(). */
    public static function fromPemFile(string $path): self
    {
        if (str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The private key file could not be read.');
        }
        $pem = @file_get_contents($path);
        if ($pem === false) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The private key file could not be read.');
        }

        return self::fromPem($pem);
    }

    /** Signs exact base bytes with SHA-256 and returns the required 64-byte P1363 form. */
    public function sign(#[\SensitiveParameter] string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            self::clearOpenSslErrors();
            throw new \Anis\Partners\Errors\AnisPartnersRuntimeException('OpenSSL could not sign the request.');
        }

        if (!is_string($signature)) {
            throw new \Anis\Partners\Errors\AnisPartnersRuntimeException('OpenSSL returned an invalid signature value.');
        }

        return EcdsaSignatureFormat::derToP1363($signature);
    }

    /** Exports the public P-256 coordinates needed to identify and compare this credential. */
    public function publicJwk(): PartnerJwk
    {
        $details = openssl_pkey_get_details($this->key);
        $ec = $details['ec'] ?? null;
        if (!is_array($ec) || !isset($ec['x'], $ec['y']) || !is_string($ec['x']) || !is_string($ec['y'])) {
            throw new \Anis\Partners\Errors\AnisPartnersRuntimeException('OpenSSL did not expose the P-256 public coordinates.');
        }

        return new PartnerJwk(
            kty: 'EC',
            crv: 'P-256',
            x: Base64Url::encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            y: Base64Url::encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        );
    }

    /** Associates this key with the credential ID issued when the public key was enrolled. */
    public function forKey(string $keyId): KeyedSigner
    {
        return new KeyedSigner($this, $keyId);
    }

    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
