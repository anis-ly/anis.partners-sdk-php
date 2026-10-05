# Getting started

## 1. Enroll a key

Ask Anis for an invitation id and one-use enrollment token. Generate a P-256 key pair and protect its private half
before submitting the public half. The invitation accepts one key, so save the private key first.

```php
<?php

use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Verification\PartnerJwk;

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($key === false || !openssl_pkey_export($key, $pem)) {
    throw new RuntimeException('Could not create the P-256 key.');
}
if (file_put_contents('/secure/partner-key.pem', $pem, LOCK_EX) === false) {
    throw new RuntimeException('Could not save the private key.');
}
chmod('/secure/partner-key.pem', 0600);
$signer = PemP256Signer::fromPem($pem);
$jwk = $signer->publicJwk();
$enrollment = EnrollmentClient::create('https://partners.example', $invitationId, $enrollmentToken);
$submitted = $enrollment->submitKey(new EnrollmentKeyRequest(
    $jwk,
    new DateTimeImmutable('now', new DateTimeZone('UTC')),
    new DateTimeImmutable('+1 year', new DateTimeZone('UTC')),
));
$status = $enrollment->prove($submitted, $signer);

printf("Key id: %s\nSafety code: %s\n", $submitted->keyId, $submitted->safetyCode);
```

The safety code is a short check derived from the key fingerprint. Anis staff call your registered technical contact;
read the code from your own software during that call. Do not send a fingerprint by email or chat. `submitKey()` also
compares the returned thumbprint with the public key you submitted before it returns a result.

After `prove()` reports `accepted`, wait for Anis staff to verify the safety code and confirm the key. Poll
`$enrollment->getStatus()` until `state` is `active`. A proved key remains unavailable until that confirmation.

## 2. Configure the client

Install `anis-ly/partners` and a PSR-18 client such as Guzzle, or Symfony HTTP Client with Nyholm PSR-7. The SDK finds
the installed HTTP client and PSR-17 message factories when creating the client.

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
$signer = PemP256Signer::fromPemFile('/secure/partner-key.pem')->forKey($submitted->keyId);
$client = AnisPartnersClient::create($options, $signer);
```

The authority is the exact HTTPS authority Anis issued. There is no environment selector: each deployment has its own
authority, keys, and data. Signature lifetime is 1–60 seconds. HTTP timeouts are configured on your PSR-18 client.
The key is passed separately because each partner chooses its own key custody.

## 3. Make the first call

```php
$profile = $client->profile()->get();
$scopes = $profile->application?->scopes ?? [];
```

Scopes reflect current access. Read them from the current profile rather than relying on a cached copy. See
[Routes and permissions](routes-and-permissions.md) for the permission each route requires.

## When a signature will not verify

Use the signed self-check to see the request facts Anis reconstructed:

```php
$diagnostic = $client->diagnostics()->checkSignature();
var_dump($diagnostic->method, $diagnostic->authority, $diagnostic->path, $diagnostic->canonicalQuery, $diagnostic->coveredComponents);
```

It requires `diagnostics:use`. If it succeeds, compare its method, authority, path, query, and covered components
with the request that failed; also check whether a proxy changed the body after it was digested. `invalid_credentials`
can mean a wrong key id or key file, a key that is not active, a revoked or expired key, a host clock outside the
allowed window, or a different authority. `insufficient_scope` means a missing permission or a calling network that
Anis has not approved. Contact support@anis.ly with the request id if the self-check does not explain the failure.

## Laravel

A Laravel package is planned separately. No date has been set. The SDK can be used directly from a Laravel service
provider today; see [Caching](caching.md) for a shared PSR-16 cache.
