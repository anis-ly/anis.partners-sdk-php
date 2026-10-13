# Anis Partner SDK for PHP

Version **2.0.1**. **1.x is deprecated — upgrade.**

The PHP client for the Anis Partner API. It signs every request, verifies every answer Anis signs — orders, card
reveals, access stops, enrollment and the signature self-check — and returns typed operations and order outcomes. See [test coverage](tests/PARITY.md) for local verification evidence.

> **Disclaimer.** This SDK is an optional helper provided free of charge under the MIT License, "as is", without
> warranty of any kind. Anis (Aniscom for Technical Services) accepts no responsibility or liability for its use or for
> any loss arising from it. You remain responsible for your own integration — recording orders before you send them,
> recovery, key custody and testing. The source code is public: read it to understand exactly what it does before you
> rely on it. You do not need an SDK — you can integrate directly with the Anis Partner API using the documentation at
> https://developers.anis.ly.

Requires PHP 8.2–8.5 and OpenSSL. Install the package and one PSR-18 HTTP client. The SDK discovers an
installed PSR-18 client and PSR-17 factories when you create the client.

```bash
composer require anis-ly/partners:^2.0 guzzlehttp/guzzle
```

Alternatively install `symfony/http-client` and `nyholm/psr7` as your HTTP client and message implementation.

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\OrderNotPlaced;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Models\OrderProcessing;
use Anis\Partners\Models\OrderReplayed;
use Anis\Partners\Signing\PemP256Signer;

$key = PemP256Signer::fromPemFile('/secure/partner-key.pem');
$signer = $key->forKey('3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43');
$client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $signer);

$profile = $client->profile()->get();
foreach ($client->wallets()->list() as $wallet) {
    if (!$wallet instanceof \Anis\Partners\Models\Wallet) {
        throw new RuntimeException('A wallet page contained an unexpected value.');
    }
    printf("%s %s\n", $wallet->name ?? $wallet->id, $wallet->balance?->amount() ?? 'unavailable');
}

// Generate a fresh random UUID v4 per purchase and persist it with the exact request before create().
$bytes = random_bytes(16);
$bytes = substr_replace($bytes, chr((ord($bytes[6] ?? "\0") & 0x0f) | 0x40), 6, 1);
$bytes = substr_replace($bytes, chr((ord($bytes[8] ?? "\0") & 0x3f) | 0x80), 8, 1);
$hex = bin2hex($bytes);
$operationId = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
$walletId = 'e918127b-6ca9-4d69-8b0d-6aa376e2e7d3';
$cardId = 'b672ca4e-c751-4507-8069-326496525a98';
$unitPrice = Money::of('10.500', 'LYD');
$request = new CreateOrderRequest($cardId, 2, $unitPrice, $unitPrice->multiply(2));
persistOrderIntent($operationId, $walletId, $request); // Your durable journal must finish before create().
$outcome = $client->orders()->create($walletId, $operationId, $request);

if ($outcome instanceof OrderCompleted) {
    storeCredentialsSecurely($outcome->credentials); // First action: store any released vouchers/serials safely.
}

match (true) {
    $outcome instanceof OrderCompleted => print('Completed.' . PHP_EOL),
    $outcome instanceof OrderProcessing => print("Wait {$outcome->retryAfterSeconds}s, then resume the same id." . PHP_EOL),
    $outcome instanceof OrderReplayed => print('Previously completed; credentials are not returned again.' . PHP_EOL),
    $outcome instanceof OrderNotPlaced => print("Not placed: {$outcome->refusal->errorCode->value}" . PHP_EOL),
    $outcome instanceof OrderOutcomeUnknown => print("Unknown; resume {$outcome->operationId} with the same request." . PHP_EOL),
    default => throw new LogicException('Unexpected order result.'),
};
```

Read [Getting started](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/getting-started.md) before enrolling a key, then read [Orders and recovery](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/orders-and-recovery.md)
before moving money. Using Laravel? See [Using the SDK with Laravel](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/laravel.md). The PHP example is in [samples/console](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/samples/console/README.md).

## Catalogue and access stops

```php
$cardId = 'b672ca4e-c751-4507-8069-326496525a98';
$soldCardId = '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046';
$otherKeyId = '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c';
$card = $client->catalogue()->getCard($walletId, $cardId);
$owned = $client->ownedCards()->get($walletId, $soldCardId);
$name = $owned->card?->name?->en;
$currency = $client->wallets()->get($walletId)->balance?->currency;

$revoked = $client->access()->revokeKey($otherKeyId, 'Key custody incident');
$suspended = $client->access()->suspendApplication('Emergency maintenance');
// Staff must resume a suspended application before it can send further admitted requests.
```

`MaskedCard` exposes `soldCardId`, `operationId`, invoice details and a shared `CardSummary` containing face value,
logo, subcategory and category. Reveals and order `soldCards` use `RevealedCredential` with the same summary.
Catalogue quantity limits are a minimum of 1 and a platform maximum of 100. Wallet currency is `balance.currency`.

## Refusals built into the design

- You provide and persist the order id; a new id after a timeout could make a second purchase.
- Five explicit result types distinguish completed, processing, replayed, not placed, and unknown orders.
- Credentials are available on the first completion only; later recovery uses the same id and never silently buys again.
- On a route whose answers Anis signs (orders, reveals, access stops, enrollment, the signature self-check), an answer that is unsigned or fails verification is discarded — success and refusal alike. Verification cannot be disabled.
- The response-key document is cached for five minutes by default and at most five minutes. Retired keys stay published for 90 days after retirement, and compromised key ids remain in `revoked[]` for 90 days. See [Signing-key cache](docs/caching.md) and [Security](docs/security.md).
- Information reads — profile, wallets, catalogue, and owned-card lists and details — are not signed by Anis, so the SDK returns them without verifying a signature; HTTPS protects them. They never fetch Anis's signing keys, so they keep working while those keys cannot be fetched. See [Routes and permissions](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/routes-and-permissions.md).
- The private key stays behind `P256Signer`; the included PEM signer is for PEM files readable only by the application user, and a KMS/HSM signer can implement the same interface.

## Coverage and proof

The client covers profile, wallet, catalogue, order, owned-card, reveal, diagnostic, access-stop, enrollment, and response-key routes. Set timeouts and disable redirects on your PSR-18 HTTP client; signed partner requests must not be silently replayed to another location.
Conformance tests use the published request, response, safety-code, and enrollment vectors. Contract tests compare route paths, signature kinds, which routes' answers are signed, public error codes, enrollment fields, and signature-component order with the checked-in contracts. The repository sample is excluded from Composer archives; clone the repository or copy the sample into your application and update its autoload path.

Typed error cases are generated from `contracts/error-catalogue.json`. Run `php tools/generate-errors.php` from a repository clone when updating the generated enum; the generator is not included in Composer archives.

See [Errors](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/errors.md), [Security](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/security.md), [Observability](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/observability.md), and
[Routes and permissions](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/routes-and-permissions.md).

## License

MIT. See [LICENSE](LICENSE).
