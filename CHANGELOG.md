# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `CreateDirectRechargeRequest::$contactPhone`: an optional phone the Anis exchanges team calls about the direct recharge (a Libyan mobile or an international number). When omitted, Anis uses the phone saved for the partner. It is part of the request's idempotency: the same `Idempotency-Key` with a different value is an `idempotency_conflict`.

## [2.2.0] - 2026-10-27

### Added

- Charge electricity meters and read receipts, tokens and refunds with safe recovery after an interrupted request.
- Quote a direct recharge, follow direct recharges and correct recipient details while a direct recharge is paused; recipient corrections return the same DirectRechargeProcessing result as 202 creates, with retry delay and read Location while the read side catches up.
- Manage a webhook endpoint and request delivery again when a notification was missed.
- Verify notifications before reading their contents and keep recipient field errors available to the integration.

### Changed

- A meter charge that failed while its refund is still `pending` is no longer final: `meterCharges()->create()` and `resume()` return `MeterChargeProcessing` with the read, because money was taken and the refund has not landed. `refunded` and `notCharged` stay final.
- `idempotency_conflict` on a meter charge or direct recharge create or resume returns `MeterChargeOutcomeUnknown` or `DirectRechargeOutcomeUnknown` instead of `...NotPlaced`, because the original request may already have been accepted and charged. Read the operation by its id with `get()` before any new attempt.
- `get()` on a meter charge or direct recharge can answer the recorded refusal of the create (409 or 422, `Idempotency-Replayed: true`); it throws the typed `AnisApiException`, which is the definitive answer for that id. This is documented and tested.
- A webhook address must have a host name that contains a dot. Public text now says "the electricity company" and "direct recharge"; wire names are unchanged.
- The install line uses `^2.2`.

### Security

- The answer verifier now rejects a signing key whose `purpose` is not `answer`; a key document without `purpose` is still accepted.
- A malformed `notBefore` or `retiredAt` in the signing-key document now gives the typed `key_rejected` refusal instead of a parser exception.

## [2.1.0] - 2026-10-18

### Added

- Catalogue subcategories expose `categoryType` as local or international, and unknown when absent.
- Catalogue card categories expose `type` as local or international, and unknown when absent; owned cards, reveals and orders are unchanged.

## [2.0.1] - 2026-10-13

### Fixed

- A wallet whose balance is below zero — while it uses allowed debt — can be read again. In 2.0.0, listing or reading such a wallet failed with an error. Amounts in Anis's answers may carry a leading minus sign (`-150.000`); `-0.000` reads as zero. `Money::of()` and `new Money()` accept a negative amount, and `amount()` prints it correctly (`-0.075`).
- Money is limited to the same size either side of zero (`PHP_INT_MAX` thousandths).
- Prices you send are unchanged: an order still refuses a unit price that is not greater than zero, before anything is sent.
- Documentation: the spending-cap refusal (`spending_limit_exceeded`) in Orders and recovery and in the Laravel guide.

## [2.0.0] - 2026-10-13

### Breaking changes

- 1.x is deprecated — upgrade to 2.0.0.
- Signed answers require `tag="anis-response-v2"` and bind both raw request signature fields.
- Response-key verification rejects compromised keys, retired keys signing after retirement, and any published key signing before its notBefore (active, next and retired).
- The signing-key document is cached for 5 minutes by default and at most 5 minutes; ClientOptions and HttpSigningKeySource refuse more than 300 seconds.
- An unknown response key id re-fetches the key document at most once per 30 seconds per key source, failed fetches included.
- Documentation: retired keys stay published for 90 days after retirement.
- Owned-card routes use `/owned-cards`; `MaskedCard` uses `soldCardId`, `operationId` and nested `CardSummary`.
- Reveals and order credentials include the full card summary. Catalogue cards include logo and category.
- Wallet currency is carried by `balance.currency`; wire Money amounts are nonnegative decimals with three places and uppercase currency.

### Added

- Recognise spending_limit_exceeded (HTTP 429): a spending-capped order is not placed and is reported as LimitExceededException with the Retry-After delay until the spending window ends.
- `access()->suspendApplication($reason)` and `access()->revokeKey($keyId, $reason)` with signed JSON reasons.
- `catalogue()->getCard($walletId, $cardId)` for a single wallet-priced catalogue card.

## [1.1.0] - 2026-10-07

### Changed

- Selective response signing. Anis now signs only the answers that move money, deliver card codes, or establish a
  key: orders (create and read), card and invoice reveals, the four enrollment routes, and the signature self-check.
  On those routes every answer, success and refusal, is still verified, and an answer without a signature is still
  refused (`signature_missing`).
- The information reads — profile, wallets, the four catalogue reads, and owned-card list and detail — are no longer
  signed by Anis. The SDK returns their answers, and maps their refusals to typed errors, without verifying a
  signature, and ignores a signature header if one is present. They no longer fetch Anis's signing keys, so they keep
  working while the keys cannot be fetched. HTTPS protects them.
- The choice is explicit per route (`PartnerRoute::$signsResponse`, `PartnerRoutes::signsResponse()`); it never depends
  on whether an answer carries a signature, and a route outside the catalogue is treated as signed.
- Every request is still signed exactly as before.

## [1.0.0] - 2026-10-06

### Added

- Signed P-256 partner requests and verified Anis responses.
- Typed operations for profile, wallets, catalogue, orders, owned cards, diagnostics, and credential enrolment.
- Durable order recovery outcomes and explicit resume guidance.
- Structured PSR-3 logs and OpenTelemetry traces and metrics.
- PHP 8.2 support with PSR HTTP interfaces.

### Changed

- Partners can use an installed PSR-18 client and PSR-17 factories discovered by the package.
