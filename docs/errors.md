# Errors and refusals

Catch `Anis\Partners\AnisPartnersException` to catch every SDK-specific exception. `AnisApiException::getCode()` is the HTTP status integer; `errorCode()` and the `errorCode` property carry the typed Anis code. `rawCode` preserves an unrecognized wire spelling. Exception fields are read-only after construction.

A verified refusal is safe to inspect through its status, typed code, request id, retry delay, retryability, replay marker, and problem. Do not branch on the message or localized title. Unknown wire codes map to `ErrorCode::Unknown` while preserving `rawCode`.

| Anis code | Exception | Order handling |
|---|---|---|
| `price_changed`, `insufficient_balance`, `quantity_unavailable`, `card_unavailable`, `allowed_debt_consent_required`, owner or daily limits, validation errors, and other definitive purchase refusals | Typed `AnisApiException` subclass where available | `OrderNotPlaced` on a first call; fix the cause before using a new operation id |
| `invalid_credentials`, `signature_expired`, `insufficient_scope`, `wallet_not_granted`, `malformed_signed_request`, `replay_detected`, or a transient refusal | Typed refusal | `OrderOutcomeUnknown`; restore access or wait, then resume the same operation id |

A request-signing exception means the request was not sent. An unverifiable answer, unavailable signing-key document, or malformed verified response is an SDK exception and is safe to catch through the common interface. Malformed-response messages and causes do not retain response body text, which could contain a voucher.

`OrderNotPlaced` means Anis confirmed that nothing was purchased. `OrderOutcomeUnknown` means the purchase may have completed; keep the original id and request and call `resume()` after the suggested delay. See [Orders and recovery](orders-and-recovery.md).
