# Routes and permissions

| PHP call | Route | Required permission | Request body | Answer signed by Anis |
|---|---|---|---|---|
| `profile()->get()` | `GET /v1/profile` | `profile:read` | none | no |
| `wallets()->list()` / `listPage()` | `GET /v1/wallets` | `wallets:read` | none | no |
| `wallets()->get($walletId)` | `GET /v1/wallets/{walletId}` | `wallets:read` | none | no |
| `catalogue()->listCategories()` / `listCategoriesPage()` | `GET /v1/wallets/{walletId}/catalog/categories` | `catalogue:read` | none | no |
| `catalogue()->listSubcategories()` / `listSubcategoriesPage()` | `GET /v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories` | `catalogue:read` | none | no |
| `catalogue()->getSubcategory()` | `GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}` | `catalogue:read` | none | no |
| `catalogue()->listCards()` / `listCardsPage()` | `GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards` | `catalogue:read` | none | no |
| `orders()->create()` / `resume()` | `POST /v1/wallets/{walletId}/orders` | `orders:create` | order JSON | yes — verified |
| `orders()->get($operationId)` | `GET /v1/orders/{operationId}` | `orders:read` or own-order `orders:create` | none | yes — verified |
| `ownedCards()->list()` / `listPage()` | `GET /v1/wallets/{walletId}/cards` | `cards:read` | none | no |
| `ownedCards()->get()` | `GET /v1/wallets/{walletId}/cards/{soldCardId}` | `cards:read` | none | no |
| `ownedCards()->reveal()` | `POST /v1/wallets/{walletId}/cards/{soldCardId}/reveal` | `cards:reveal` | no body | yes — verified |
| `ownedCards()->revealInvoice()` | `POST /v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal` | `cards:reveal` | no body | yes — verified |
| `diagnostics()->checkSignature()` | `POST /v1/diagnostics/signature` | `diagnostics:use` | `{}` | yes — verified |
| `EnrollmentClient::get()` | `GET /v1/enrollments/{invitationId}` | enrollment token | none | yes — verified |
| `EnrollmentClient::submitKey()` | `POST /v1/enrollments/{invitationId}/keys` | enrollment token | public key | yes — verified |
| `EnrollmentClient::submitProof()` / `prove()` | `POST /v1/enrollments/{invitationId}/proof` | enrollment token | proof | yes — verified |
| `EnrollmentClient::getStatus()` | `GET /v1/enrollments/{invitationId}/status` | enrollment token | none | yes — verified |
| Internal verification | `GET /.well-known/partner-signing-keys.json` | public | none | no |

List methods return generators and follow every cursor page. The `*Page()` methods return one page when an
application manages its own cursor. Reveal requests contain zero body bytes; the diagnostic request contains exactly `{}`.

## Which answers are verified

Anis signs only the answers that move money, deliver card codes, or establish a key: orders, the two reveals,
enrollment, and the signature self-check. On those routes **every** answer — the success and each refusal — is
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
