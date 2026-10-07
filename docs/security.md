# Security and key custody

## Keep the private key in your custody

`P256Signer` accepts the exact signature-base bytes and returns a 64-byte P-256 signature. A KMS or HSM can implement it without exporting private key material. `PemP256Signer` supports PEM files readable only by the application user; passphrase-protected PEM files are not supported.

```php
use Anis\Partners\Signing\P256Signer;

final class KmsSigner implements P256Signer
{
    public function __construct(private readonly KmsClient $kms) {}

    public function sign(#[\SensitiveParameter] string $data): string
    {
        return $this->kms->signP256Sha256($data);
    }
}
```

Create a private key file with mode `0600` before writing key bytes. Keep enrollment tokens, PEM contents, request signatures, nonces, vouchers, and serial numbers out of logs and error reports. The SDK redacts these from supported native inspection paths; JSON serialization of a revealed credential intentionally retains its wire fields so a partner can store its own credential record securely.

## Rotate a key

Partner request-key rotation is started by Anis staff. The partner enrolls and proves a replacement key through the normal enrollment flow. During the overlap, Anis accepts requests signed by either enrolled partner key; after the old key is revoked, requests signed with it are refused as `invalid_credentials`.

Anis response-key publication is separate: Anis publishes the public keys its clients use to verify the Anis answers it signs (orders, reveals, enrollment, and the signature self-check). Partners do not rotate their request credential by changing this response-key document. There is no environment selector; configure the authority and credential explicitly for each deployment.

Revealed voucher and serial values are intentionally available to partner code for fulfillment and storage. `json_encode()`, `serialize()`, and `var_export()` output can contain those codes; never write those outputs to logs or diagnostic reports. `var_dump()` and `print_r()` redact the credential fields, but applications should still keep returned credentials out of general-purpose diagnostics.

Use HTTPS for every non-loopback authority. Plain HTTP is accepted only for `localhost`, `127.0.0.1`, or `::1` during local testing. HTTPS is what protects the answers Anis does not sign — profile, wallets, catalogue, owned-card lists and details, and the signing-key document; see [Which answers are verified](routes-and-permissions.md#which-answers-are-verified).
