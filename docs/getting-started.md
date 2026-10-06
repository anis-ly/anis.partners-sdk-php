# Getting started

> **Disclaimer.** This SDK is an optional helper provided free of charge under the MIT License, "as is", without
> warranty of any kind. Anis (Aniscom for Technical Services) accepts no responsibility or liability for its use or for
> any loss arising from it. You remain responsible for your own integration — recording orders before you send them,
> recovery, key custody and testing. The source code is public: read it to understand exactly what it does before you
> rely on it. You do not need an SDK — you can integrate directly with the Anis Partner API using the documentation at
> https://developers.anis.ly.

## Enroll a key

Ask Anis for an invitation id and one-use enrollment token. Generate a P-256 key pair and create its private file with owner-only permissions before writing any key bytes.

```php
<?php

use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Signing\PemP256Signer;

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($key === false || !openssl_pkey_export($key, $pem)) {
    throw new RuntimeException('Could not create the P-256 key.');
}
if (!is_string($pem)) {
    throw new RuntimeException('OpenSSL did not return a private key PEM.');
}
$keyPath = '/secure/partner-key.pem';
$oldMask = umask(0077);
try {
    $file = fopen($keyPath, 'x');
} finally {
    umask($oldMask);
}
if ($file === false) {
    throw new RuntimeException('The key file already exists or could not be created; choose a new path.');
}
$written = fwrite($file, $pem);
fclose($file);
if ($written !== strlen($pem) || !chmod($keyPath, 0600)) {
    // Delete only the new file opened exclusively by this code.
    @unlink($keyPath);
    throw new RuntimeException('Could not safely save the private key.');
}

$signer = PemP256Signer::fromPemFile($keyPath);
$jwk = $signer->publicJwk();
$enrollment = EnrollmentClient::create('https://partners.example', $invitationId, $enrollmentToken);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$submitted = $enrollment->submitKey(new EnrollmentKeyRequest($jwk, $now, $now->modify('+365 days')));
$status = $enrollment->prove($submitted, $signer);
if ($status->proofState !== 'accepted') {
    throw new RuntimeException('Anis did not accept the key proof.');
}
printf("Key id: %s\nSafety code: %s\n", $submitted->keyId, $submitted->safetyCode);
```

The safety code comes from the submitted public key's fingerprint. Compare it with Anis staff over your registered support channel; do not send the fingerprint by email or chat. `submitKey()` checks Anis's returned thumbprint against the submitted key before returning.

After the proof is accepted, wait for staff to confirm the key. Poll `$enrollment->getStatus()` until the enrollment state is active. A proved key cannot sign partner requests before that confirmation.

## Configure the client

Install `anis-ly/partners` and one PSR-18 client plus PSR-17 message factories. Guzzle or Symfony HTTP Client with Nyholm PSR-7 are common choices; the SDK discovers installed implementations.

```php
use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Signing\PemP256Signer;

$options = ClientOptions::fromArray([
    'authority' => 'https://partners.example',
    'signatureLifetimeSeconds' => 60,
    'acceptLanguage' => 'Arabic',
    'signingKeyCacheSeconds' => 600,
]);
$signer = PemP256Signer::fromPemFile('/secure/partner-key.pem')->forKey($keyId);
$client = AnisPartnersClient::create($options, $signer);
```

Use the HTTPS authority Anis issued. Plain HTTP is accepted only for loopback testing. Signature lifetime is 1–60 seconds. Set connection and request timeouts on your PSR-18 client; redirect following must be disabled so signed requests are never replayed to another location.

## Make a call

```php
$profile = $client->profile()->get();
$scopes = $profile->application->scopes ?? [];
```

Scopes reflect current access. Read them from the current profile rather than relying on a cached copy. See [Routes and permissions](routes-and-permissions.md) for each route's permission.

For every purchase, generate a fresh random UUID version 4 operation id and store it with the exact request before calling `create()`. Reuse that same id only with `resume()` during recovery.

## When a signature will not verify

Use the signed self-check to inspect the request facts Anis reconstructed:

```php
$diagnostic = $client->diagnostics()->checkSignature();
var_dump($diagnostic->method, $diagnostic->authority, $diagnostic->path, $diagnostic->canonicalQuery, $diagnostic->coveredComponents);
```

It requires `diagnostics:use`. Compare the method, authority, path, query, and covered components with the failed request; also check whether a proxy changed the body after it was digested. `invalid_credentials` can mean a wrong key id or key file, a key that is not active, a revoked or expired key, a host clock outside the allowed window, or a different authority. Contact support@anis.ly with the request id if the self-check does not explain the failure.
