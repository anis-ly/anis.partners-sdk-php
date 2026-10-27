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

The sample writes each order intent before the call, then journals its outcome and any released credentials in an owner-only (`0600`) file. Replays leave stored credentials untouched; unknown outcomes keep prior credentials; `resume` loads the operation id from that journal. A completion with withheld codes and a recovery-exhausted status print distinct messages. Use `order --preview` to inspect the order flow while still writing the intent before its held request.

The sample reads `settings.json` from the current directory or this folder. Values can be overridden with:

```bash
export ANIS_PARTNERS_AUTHORITY=https://partners.example
export SAMPLE_KEY_FILE=/secure/partner-key.pem
export SAMPLE_KEY_ID=3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43
export SAMPLE_ENROLLMENT_TOKEN=your-one-use-token
export SAMPLE_ORDERS_FOLDER=/secure/partner-orders
```

`settings.json` may hold `AnisPartners.authority`, `Sample.keyFile`, `Sample.keyId`, and `Sample.ordersFolder`.
Enrollment accepts `--invitation`, optional `--key-file`, and `--days`; set `SAMPLE_ENROLLMENT_TOKEN` in the environment instead of placing the one-use token on the command line. It saves a new P-256 private key
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
structured debug and information logs. A regular run uses Guzzle configured with a 15-second timeout and redirects disabled through the SDK's PSR-18 client interface.

An order's `OrderOutcomeUnknown` means resume the same operation id and request. `OrderCompleted` credentials should
be stored in protected custody; the sample reports only their count after an order and masks reveal values by default.

## Meter, direct recharge and notifications

`products-sample.php` shows the 2.2.0 calls: meter packages, `meter-charge`, `meter-resume`, `meter-status`, direct
recharge products, `recharge-quote`, `recharge`, `recharge-resume`, `recharge-status`, `recharge-fix`, and the
`webhook-*` commands for the endpoint, a test, the delivery list and a redelivery. Run `php samples/console/products-sample.php help`.
It reads `ANIS_PARTNERS_AUTHORITY`, `SAMPLE_KEY_FILE` and `SAMPLE_KEY_ID`, and writes each create's operation id and
exact request to an owner-only file in `SAMPLE_PRODUCTS_FOLDER` before anything is sent; `*-resume` reads that file.
It never prints meter tokens or recipient details. `recharge-fix` consumes `DirectRechargeProcessing`, the same result
as a 202 create, and prints the operation id, read Location and retry delay. The acknowledgement has no direct recharge read. It does not fetch a direct recharge or claim the correction is already visible; use `recharge-status` later or
wait for the webhook. After a lost or malformed answer, read before deciding whether to send another correction.

`samples/webhook-receiver/receive.php` is a separate receiving handler: it reads the raw body, verifies the delivery,
answers the verification challenge, and marks where your durable deduplication belongs. Configure `WEBHOOK_URL` as
the registered expected address and `WEBHOOK_ORIGIN` as the trusted public deployment origin (for example
`https://pos.example.ly`). It combines that origin with the received raw path/query and reads the actual request method;
Host and forwarding headers never supply the trusted origin.
