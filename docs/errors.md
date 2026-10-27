# Errors and refusals

Catch `Anis\Partners\AnisPartnersException` to catch every SDK-specific exception. `AnisApiException::getCode()` is the HTTP status integer; `errorCode()` and the `errorCode` property carry the typed Anis code. `rawCode` preserves an unrecognized wire spelling. Exception fields are read-only after construction.

A refusal is safe to inspect through its status, typed code, request id, retry delay, retryability, replay marker, and problem; recipient messages in a validation problem can repeat entered details, so keep them out of logs (see Recipient field errors below). Do not branch on the message or localized title. Unknown wire codes map to `ErrorCode::Unknown` while preserving `rawCode`.

| Anis code | Exception | Order handling |
|---|---|---|
| `price_changed`, `insufficient_balance`, `quantity_unavailable`, `card_unavailable`, `allowed_debt_consent_required`, `spending_limit_exceeded`, owner or daily limits, validation errors, and other definitive purchase refusals | Typed `AnisApiException` subclass where available | `OrderNotPlaced` on a first call; fix the cause before using a new operation id |
| `invalid_credentials`, `signature_expired`, `insufficient_scope`, `wallet_not_granted`, `malformed_signed_request`, `replay_detected`, or a transient refusal | Typed refusal | `OrderOutcomeUnknown`; restore access or wait, then resume the same operation id |

A request-signing exception means the request was not sent. An unverifiable answer on a signed route (orders, reveals, access stops, enrollment, the signature self-check — including one that carries no signature), an unavailable signing-key document, or a malformed response is an SDK exception and is safe to catch through the common interface. Malformed-response messages and causes do not retain response body text, which could contain a voucher.

Meter charge and direct recharge creates use the same rules with `MeterChargeNotPlaced` and `DirectRechargeNotPlaced`.
The exception is `idempotency_conflict` (409): it does not say the original request failed, because it may already have been
accepted and charged. A create or resume that meets it returns `MeterChargeOutcomeUnknown` or `DirectRechargeOutcomeUnknown`
with the typed refusal in `cause`; read the operation by its id with `get()` before any new attempt. A `get()` that
answers a recorded refusal (409 or 422 with `Idempotency-Replayed: true`) throws the typed `AnisApiException`, which is the
definitive answer for that id.

`OrderNotPlaced` means Anis confirmed that nothing was purchased. `OrderOutcomeUnknown` means the purchase may have completed; keep the original id and request and call `resume()` after the suggested delay. See [Orders and recovery](orders-and-recovery.md).

`spending_limit_exceeded` (429) means the application's spending cap for the current window is used up. It is a final,
non-retryable refusal reported as `LimitExceededException`; `retryAfter` says when a new order, with a new operation
id, can succeed. Do not poll or repeat the refused order.

Seven codes belong to meter charges, direct recharge and notifications. None is retryable:

| Anis code | HTTP | Meaning | What to do |
|---|---|---|---|
| `meter_rejected` | 422 | The electricity company refused the meter number | Correct the meter number and use a new operation id |
| `package_unavailable` | 409 | The meter package is not available | Choose an available package and use a new operation id |
| `direct_recharge_product_unavailable` | 409 | The product is unavailable for this wallet | Choose an available product priced for this wallet |
| `amount_out_of_range` | 422 | The amount is outside the product limits | Choose an amount within the quoted limits |
| `direct_recharge_not_suspended` | 409 | A recipient correction was sent while the recharge is not paused | Read the recharge; a correction needs the status `suspendedDataError` |
| `webhook_endpoint_invalid` | 422 | The notification address breaks the rules | Use HTTPS on port 443 with a host name that contains a dot, no user name, no fragment, at most 2048 characters |
| `webhook_endpoint_unverified` | 409 | The endpoint is not verified | Set the endpoint and complete verification before testing or redelivering |

The first four are definitive refusals of a create, so the result is `MeterChargeNotPlaced` or `DirectRechargeNotPlaced`.
The endpoint codes and `direct_recharge_not_suspended` answer signed changes, which are never run through create recovery:
they surface as ordinary exceptions.

## Recipient field errors

A `validation_failed` (422) refusal of a direct recharge can say which recipient detail was wrong, for example
`{"fields":{"recipient_number":"Invalid number"}}`. `AnisApiException::fieldErrors()` returns those messages as an
array keyed by the field and ignores any shape it does not recognise; `problem->extensions` still holds the answer unchanged,
including members this SDK does not know. Show the messages to the operator or customer, but do not branch on them and
do not write recipient messages or the whole problem to logs: they can repeat the details that were entered.

Response verification also exposes `profile_unexpected`, `key_compromised`, `key_retired`, and `key_not_yet_valid`. See [Security](security.md#response-verification-in-200) for the closed vocabulary and key timing rules.
