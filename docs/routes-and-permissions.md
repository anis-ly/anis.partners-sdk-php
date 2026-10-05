# Routes and permissions

| PHP call | Route | Required permission | Request body |
|---|---|---|---|
| `profile()->get()` | `GET /v1/profile` | `profile:read` | none |
| `wallets()->list()` / `listPage()` | `GET /v1/wallets` | `wallets:read` | none |
| `wallets()->get($walletId)` | `GET /v1/wallets/{walletId}` | `wallets:read` | none |
| `catalogue()->listCategories()` / `listCategoriesPage()` | `GET /v1/wallets/{walletId}/catalog/categories` | `catalogue:read` | none |
| `catalogue()->listSubcategories()` / `listSubcategoriesPage()` | `GET /v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories` | `catalogue:read` | none |
| `catalogue()->getSubcategory()` | `GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}` | `catalogue:read` | none |
| `catalogue()->listCards()` / `listCardsPage()` | `GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards` | `catalogue:read` | none |
| `orders()->create()` / `resume()` | `POST /v1/wallets/{walletId}/orders` | `orders:create` | order JSON |
| `orders()->get($operationId)` | `GET /v1/orders/{operationId}` | `orders:read` or own-order `orders:create` | none |
| `ownedCards()->list()` / `listPage()` | `GET /v1/wallets/{walletId}/cards` | `cards:read` | none |
| `ownedCards()->get()` | `GET /v1/wallets/{walletId}/cards/{soldCardId}` | `cards:read` | none |
| `ownedCards()->reveal()` | `POST /v1/wallets/{walletId}/cards/{soldCardId}/reveal` | `cards:reveal` | no body |
| `ownedCards()->revealInvoice()` | `POST /v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal` | `cards:reveal` | no body |
| `diagnostics()->checkSignature()` | `POST /v1/diagnostics/signature` | `diagnostics:use` | `{}` |
| `EnrollmentClient::get()` | `GET /v1/enrollments/{invitationId}` | enrollment token | none |
| `EnrollmentClient::submitKey()` | `POST /v1/enrollments/{invitationId}/keys` | enrollment token | public key |
| `EnrollmentClient::submitProof()` / `prove()` | `POST /v1/enrollments/{invitationId}/proof` | enrollment token | proof |
| `EnrollmentClient::getStatus()` | `GET /v1/enrollments/{invitationId}/status` | enrollment token | none |
| Internal verification | `GET /.well-known/partner-signing-keys.json` | public | none |

List methods return generators and follow every cursor page. The `*Page()` methods return one page when an
application manages its own cursor. Every response except the published signing-key document is verified before it
is returned. Reveal requests contain zero body bytes; the diagnostic request contains exactly `{}`.

The request signature covers the method, authority, path, query, and date. Mutations also sign the body digest and
one-time nonce; orders include the caller's operation id as `Idempotency-Key`.

Anis deliberately uses the same `insufficient_scope` answer for missing permission and a source network that has not
been approved. A hidden or ungranted resource is also not distinguished from a missing one. See
[Getting started](getting-started.md#when-a-signature-will-not-verify) for the self-check.
