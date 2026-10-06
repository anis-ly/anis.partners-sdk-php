# Anis Partner SDK for PHP

The PHP client for the Anis Partner API. It signs each request, verifies each response, and returns typed
operations and order outcomes. Verified end to end against a live Anis environment (October 2026).

Requires PHP 8.2–8.5 and OpenSSL. Install the package and one PSR-18 HTTP client. The SDK discovers an
installed PSR-18 client and PSR-17 factories when you create the client.

```bash
composer require anis-ly/partners guzzlehttp/guzzle
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
    printf("%s %s\n", $wallet->name ?? $wallet->id, $wallet->balance->amount());
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
before moving money. The PHP example is in [samples/console](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/samples/console/README.md).

## Refusals built into the design

- You provide and persist the order id; a new id after a timeout could make a second purchase.
- Five explicit result types distinguish completed, processing, replayed, not placed, and unknown orders.
- Credentials are available on the first completion only; later recovery uses the same id and never silently buys again.
- An unverifiable response is discarded. Verification cannot be disabled.
- The private key stays behind `P256Signer`; the included PEM signer is for PEM files readable only by the application user, and a KMS/HSM signer can implement the same interface.

## Coverage and proof

The client covers profile, wallet, catalogue, order, owned-card, reveal, diagnostic, enrollment, and response-key routes. Set timeouts and disable redirects on your PSR-18 HTTP client; signed partner requests must not be silently replayed to another location.
Conformance tests use the published request, response, safety-code, and enrollment vectors. Contract tests compare route paths, signature kinds, public error codes, enrollment fields, and signature-component order with the checked-in contracts. The repository sample is excluded from Composer archives; clone the repository or copy the sample into your application and update its autoload path.

Typed error cases are generated from `contracts/error-catalogue.json`. Run `php tools/generate-errors.php` from a repository clone when updating the generated enum; the generator is not included in Composer archives.

See [Errors](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/errors.md), [Security](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/security.md), [Observability](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/observability.md), and
[Routes and permissions](https://github.com/anis-ly/anis.partners-sdk-php/blob/main/docs/routes-and-permissions.md).

## License

MIT. See [LICENSE](LICENSE).
