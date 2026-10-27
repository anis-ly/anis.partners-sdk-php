# Routes and permissions

| PHP call | Route | Required permission | Request body | Answer signed by Anis |
|---|---|---|---|---|
| `access()->suspendApplication($reason)` | `POST /v1/application/suspend` | none for an admitted request | `{reason}` | yes — verified |
| `access()->revokeKey($keyId, $reason)` | `POST /v1/keys/{keyId}/revoke` | none for an admitted request | `{reason}` | yes — verified |
| `catalogue()->getCard($walletId, $cardId)` | `GET /v1/wallets/{walletId}/catalog/cards/{cardId}` | `catalogue:read` | none | no |
| `profile()->get()` | `GET /v1/profile` | `profile:read` | none | no |
| `wallets()->list()` / `listPage()` | `GET /v1/wallets` | `wallets:read` | none | no |
| `wallets()->get($walletId)` | `GET /v1/wallets/{walletId}` | `wallets:read` | none | no |
| `catalogue()->listCategories()` / `listCategoriesPage()` | `GET /v1/wallets/{walletId}/catalog/categories` | `catalogue:read` | none | no |
| `catalogue()->listSubcategories()` / `listSubcategoriesPage()` | `GET /v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories` | `catalogue:read` | none | no |
| `catalogue()->getSubcategory()` | `GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}` | `catalogue:read` | none | no |
| `catalogue()->listCards()` / `listCardsPage()` | `GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards` | `catalogue:read` | none | no |
| `orders()->create()` / `resume()` | `POST /v1/wallets/{walletId}/orders` | `orders:create` | order JSON | yes — verified |
| `orders()->get($operationId)` | `GET /v1/orders/{operationId}` | `orders:read` or own-order `orders:create` | none | yes — verified |
| `ownedCards()->list()` / `listPage()` | `GET /v1/wallets/{walletId}/owned-cards` | `cards:read` | none | no |
| `ownedCards()->get()` | `GET /v1/wallets/{walletId}/owned-cards/{soldCardId}` | `cards:read` | none | no |
| `ownedCards()->reveal()` | `POST /v1/wallets/{walletId}/owned-cards/{soldCardId}/reveal` | `cards:reveal` | no body | yes — verified |
| `ownedCards()->revealInvoice()` | `POST /v1/wallets/{walletId}/invoices/{invoiceId}/owned-cards/reveal` | `cards:reveal` | no body | yes — verified |
| `diagnostics()->checkSignature()` | `POST /v1/diagnostics/signature` | `diagnostics:use` | `{}` | yes — verified |
| `EnrollmentClient::get()` | `GET /v1/enrollments/{invitationId}` | enrollment token | none | yes — verified |
| `EnrollmentClient::submitKey()` | `POST /v1/enrollments/{invitationId}/keys` | enrollment token | public key | yes — verified |
| `EnrollmentClient::submitProof()` / `prove()` | `POST /v1/enrollments/{invitationId}/proof` | enrollment token | proof | yes — verified |
| `EnrollmentClient::getStatus()` | `GET /v1/enrollments/{invitationId}/status` | enrollment token | none | yes — verified |
| `meterCharges()->getMeterPackages()` / `getMeterPackagesPage()` | `GET /v1/wallets/{walletId}/meter-packages` | `meter_charges:read` | none | no |
| `meterCharges()->getMeterCharges()` / `getMeterChargesPage()` | `GET /v1/wallets/{walletId}/meter-charges` | `meter_charges:read` | none | no |
| `meterCharges()->create()` / `resume()` | `POST /v1/wallets/{walletId}/meter-charges` | `meter_charges:create` | charge JSON | yes — verified |
| `meterCharges()->get($operationId)` | `GET /v1/meter-charges/{operationId}` | `meter_charges:read` | none | yes — verified |
| `directRechargeProducts()->getDirectRechargeProducts()` / `getDirectRechargeProductsPage()` | `GET /v1/wallets/{walletId}/direct-recharge-products` | `direct_recharges:read` | none | no |
| `directRechargeProducts()->get()` | `GET /v1/wallets/{walletId}/direct-recharge-products/{productId}` | `direct_recharges:read` | none | no |
| `directRechargeProducts()->getDirectRechargeProductLocations()` / `getDirectRechargeProductLocationsPage()` | `GET /v1/wallets/{walletId}/direct-recharge-products/{productId}/locations` | `direct_recharges:read` | none | no |
| `directRechargeProducts()->quote()` | `GET /v1/wallets/{walletId}/direct-recharge-products/{productId}/quote` | `direct_recharges:read` | none | no |
| `directRecharges()->getDirectRecharges()` / `getDirectRechargesPage()` | `GET /v1/wallets/{walletId}/direct-recharges` | `direct_recharges:read` | none | no |
| `directRecharges()->create()` / `resume()` | `POST /v1/wallets/{walletId}/direct-recharges` | `direct_recharges:create` | recharge JSON | yes — verified |
| `directRecharges()->get($operationId)` | `GET /v1/direct-recharges/{operationId}` | `direct_recharges:read` | none | yes — verified |
| `directRecharges()->updateRecipient()` | `PATCH /v1/direct-recharges/{operationId}/recipient` | `direct_recharges:create` | recipient JSON | yes — verified |
| `webhookEndpoint()->get()` | `GET /v1/webhook-endpoint` | none | none | no |
| `webhookEndpoint()->set()` | `PUT /v1/webhook-endpoint` | none | `{url}` | yes — verified |
| `webhookEndpoint()->test()` | `POST /v1/webhook-endpoint/test` | none | `{}` | yes — verified |
| `webhookEvents()->getWebhookEvents()` / `getWebhookEventsPage()` | `GET /v1/webhook-events` | none | none | no |
| `webhookEvents()->redeliver($eventId)` | `POST /v1/webhook-events/{eventId}/redeliver` | none | `{}` | yes — verified |
| Internal verification | `GET /.well-known/partner-signing-keys.json` | public | none | no |

List methods return generators and follow every cursor page. The `*Page()` methods return one page when an
application manages its own cursor. Reveal requests contain zero body bytes; the diagnostic request contains exactly `{}`. Meter charge and direct recharge reads are filtered by `status`, `from` and `to` (and `meterNumber` or `productId`), newest first, with an optional page size of 1 to 100.

Recipient PATCH returns a signed **202 acknowledgement**, exposed as `DirectRechargeProcessing`, the same result
as a 202 create: operation id, `retryAfterSeconds`, verified read path in `location`, and a null `read`. Its strict
three-member wire body is the operation id, `directRecharge` type and `processing` status. Read the direct recharge later or wait for its webhook; the read can lag behind the accepted correction.
The SDK rejects a missing/wrong Location or an unexpected successful status and never replays the PATCH.

## Meter charge, direct recharge and notification permissions

- `meter_charges:create` lets the application charge a meter and also reads packages, charges and receipts.
  `meter_charges:read` reads only.
- `direct_recharges:create` lets the application create a direct recharge and correct a paused recipient, and also reads
  products, places, quotes and recharges. `direct_recharges:read` reads only.
- The webhook endpoint and delivery routes need no permission: every application manages its own endpoint. They still
  need an admitted application and an approved source network.
- Meter charges and direct recharges count under the same `orders` limit and the application's one daily spending cap
  as card orders. There is no separate cap per product.
- Operation reads, the recipient correction and the webhook routes are scoped to your application: another
  application's operation answers like a missing one.
- The wallet statement is frozen and excluded from this release.

## Which answers are verified

Anis signs only the answers that move money, deliver card codes, stop access, or establish a key: orders, the two reveals,
access stops, enrollment, and the signature self-check. On those routes **every** answer — the success and each refusal — is
verified before it is returned, and an answer without a valid signature is discarded as
`UnverifiableResponseException` (an order create reports it as `OrderOutcomeUnknown`, to be resumed). Anis does not
sign the information reads — profile, wallets, catalogue, and owned-card lists and details — nor the signing-key
document, so the SDK returns those answers, and maps their refusals to typed errors, without verifying a signature;
HTTPS protects them. They also never fetch Anis's signing keys, so they keep working while those keys cannot be fetched.

The choice is fixed per route in the SDK, matching the table above; it never depends on whether an answer happens to
carry a signature. A signature header on an information answer is ignored, and a missing one on a signed route is
always refused.

The request signature covers the method, authority, path, query, and date. Mutations also sign the body digest and
one-time nonce; orders include the caller's operation id as `Idempotency-Key`.

Anis deliberately uses the same `insufficient_scope` answer for missing permission and a source network that has not
been approved. A hidden or ungranted resource is also not distinguished from a missing one. See
[Getting started](getting-started.md#when-a-signature-will-not-verify) for the self-check.
