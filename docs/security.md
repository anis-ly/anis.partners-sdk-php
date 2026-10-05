# Security and key custody

## Keep the private key in your custody

`P256Signer` accepts the signature-base bytes and returns a 64-byte IEEE P1363 signature. It has no method to export
or describe private key material, so a KMS or HSM can implement it without exposing the key to the application.
`PemP256Signer` supports protected PEM files for development and deployments that use file custody.

```php
use Anis\Partners\Signing\P256Signer;

final class KmsSigner implements P256Signer
{
    public function __construct(private readonly KmsClient $kms) {}

    public function sign(string $data): string
    {
        // Ask the KMS for ECDSA P-256 / SHA-256 in IEEE P1363 r||s format.
        return $this->kms->signP1363($data);
    }
}
```

The HTTP signature carries P1363: two fixed-width 32-byte integers, `r` followed by `s`. OpenSSL commonly returns
ASN.1 DER, which encodes the integers with variable lengths; the included PEM signer converts it. Return P1363 from
custom signers. Never put private keys in settings, environment variables, telemetry, logs, or crash reports.

## Authority and verification

There is no environment setting. Use only the authority Anis issued for the application; keys do not move between
deployments. Every response is checked before its parsed content reaches the caller. Do not disable verification or
read an unverified body. The signing-key document is public and is fetched separately because it supplies the keys
used to verify other responses.

## Enrollment and the safety-code call

Enrollment requests use the enrollment token, but enrollment responses are also verified. `submitKey()` checks the
thumbprint Anis returns against the key you submitted. After proof, Anis staff call your registered technical contact
and ask for the safety code shown by your own application. Read it over that call; do not send fingerprints or safety
codes through email or chat.

## Rotation and expiry

Ask Anis staff to enroll a replacement key before the current key expires. During the agreed overlap, move the
signer to the replacement key id. After the overlap, the earlier key is refused. Revoke a key that is no longer in
use through Anis staff.

## Never log credentials

Order credentials and reveal results are secrets. `json_encode()` on response models gives their wire-shaped data,
including any credential fields present in a reveal or completed order; store those results in a secret store and never
serialize them to logs. The SDK also keeps signatures, signature bases, nonces, and enrollment tokens out of telemetry.

The console sample's `enrol --dry-run` uses a temporary in-memory P-256 key and does not create or modify the configured
key file. A real `enrol` saves the private key with owner-only permissions before submitting its public half.

## Clock

The client signs requests for 1–60 seconds, and accepts signed responses within a 60-second freshness window.
Keep host clocks synchronized. A clock that differs substantially from Anis can cause authentication refusals or
discarded responses; an order with no verified answer must be resumed with the same operation id.
# HTTP client redirects

The supplied PSR-18 client must not follow redirects for Partner SDK requests, including signing-key retrieval. A
redirect changes the authority or request target after signing, so the SDK needs to see the original answer. A 3xx
response is verified like every other answer before the SDK returns its refusal. Guzzle's PSR-18 `sendRequest()` does not
follow redirects; configure other clients to return a 3xx response directly.
