# Console sample

This plain PHP example shows the SDK calls for enrollment, reads, orders, recovery, reveals, diagnostics, and signing
keys. It requires PHP 8.2+, a Composer autoloader, and Guzzle for real HTTP calls. The sample is kept in the source
repository and is excluded from Composer distribution archives. Clone this repository to run it as shown below, or
copy `anis-sample.php` into your application and change its `require` line to your application's `vendor/autoload.php`.

```bash
composer require anis-ly/partners guzzlehttp/guzzle
git clone https://github.com/anis-ly/anis.partners-sdk-php.git
cd anis.partners-sdk-php
composer install
php samples/console/anis-sample.php help
```

The sample reads `settings.json` from the current directory or this folder. Values can be overridden with:

```bash
export ANIS_PARTNERS_AUTHORITY=https://partners.example
export SAMPLE_KEY_FILE=/secure/partner-key.pem
export SAMPLE_KEY_ID=3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43
export SAMPLE_ORDERS_FOLDER=/secure/partner-orders
```

`settings.json` may hold `AnisPartners.authority`, `Sample.keyFile`, `Sample.keyId`, and `Sample.ordersFolder`.
Enrollment accepts `--invitation`, `--token`, optional `--key-file`, and `--days`. It saves a new P-256 private key
with owner-only permissions before submitting the public key. `enrol --dry-run` generates an in-memory key and does
not create or modify the key file; `--preview` also keeps the key in memory because it holds the first mutation.
Keep the printed safety code for the phone call with Anis staff.

The read examples are `profile`, `wallets`, `wallet`, `categories`, `subcategories`, `subcategory`, `cards`, `owned`,
and `owned-card`. `order` reads the current wallet price and writes its operation id and request to the configured
orders folder before sending. `resume` reads that recorded request and reuses both values. `order-status` only reads
state. Optional `--operation` and `--expected-unit-price` flags let you repeat a known id or demonstrate a price
refusal; an id already in the journal cannot be overwritten. `reveal`, `reveal-invoice`, `diagnostic`, and
`signing-keys` demonstrate the corresponding explicit calls. `tour` walks available read routes using the first
returned wallet, category, subcategory, and owned card.

Add `--dry-run` to inspect one signed HTTP request without sending it. The sample prints method, URL, header names,
and body; proof signatures and private JWK members in a JSON body are redacted, and it never prints signature header
values, signature bases, or private keys. It holds the first request so no request limit, nonce, or money is spent.
Add `--preview` to send reads and hold the first request that changes something. Credentials
are masked in terminal output unless `--show-secrets` is explicitly supplied. Add `--verbose` to print the SDK's
structured debug and information logs. A regular run uses Guzzle through the SDK's PSR-18 client interface.

An order's `OrderOutcomeUnknown` means resume the same operation id and request. `OrderCompleted` credentials should
be stored in protected custody; the sample reports only their count after an order and masks reveal values by default.
