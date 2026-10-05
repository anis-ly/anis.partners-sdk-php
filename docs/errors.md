# Errors

Use `$exception->errorCode()->value` or the existing `$exception->errorCode` property for branching; `getCode()` returns
the HTTP status as a PHP exception code. The exception message is for people and may include localized presentation.
`AnisApiException` also carries `status`, `requestId`, `retryAfter`, `isRetryable`, and `isReplayed`. Unknown wire values
map to `ErrorCode::Unknown` while `rawCode` preserves the received spelling. A replay marker may classify an order
refusal as `OrderNotPlaced`; otherwise unclear order results require recovery with the same operation id.

| Public code | HTTP | PHP exception | Retryable | Order result* |
|---|---:|---|---:|---|
| `invalid_credentials` | 401 | `InvalidCredentialsException` | no | `OrderOutcomeUnknown`* |
| `signature_expired` | 401 | `InvalidCredentialsException` | yes | `OrderOutcomeUnknown`* |
| `invalid_content_digest` | 400 | `AnisApiException` | no | `OrderNotPlaced`* |
| `malformed_signed_request` | 400 | `AnisApiException` | no | `OrderOutcomeUnknown`* |
| `replay_detected` | 409 | `ReplayDetectedException` | yes | `OrderOutcomeUnknown`* |
| `source_ip_not_allowed` | 403 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `insufficient_scope` | 403 | `AuthorizationException` | no | `OrderOutcomeUnknown`* |
| `binding_not_authorized` | 403 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `resource_not_found` | 404 | `ResourceNotFoundException` | no | `OrderNotPlaced`* |
| `card_not_found` | 404 | `ResourceNotFoundException` | no | `OrderNotPlaced`* |
| `wallet_not_granted` | 404 | `ResourceNotFoundException` | no | `OrderOutcomeUnknown`* |
| `validation_failed` | 422 | `ValidationFailedException` | no | `OrderNotPlaced`* |
| `idempotency_conflict` | 409 | `IdempotencyConflictException` | no | `OrderNotPlaced`* |
| `operation_processing` | 202 | — (successful `OrderProcessing` response) | yes | `OrderProcessing`* |
| `allowed_debt_consent_required` | 402 | `AnisApiException` | no | `OrderNotPlaced`* |
| `insufficient_balance` | 409 | `InsufficientBalanceException` | no | `OrderNotPlaced`* |
| `purchase_not_allowed` | 403 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `purchase_not_allowed` | 409 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `owner_limit_exceeded` | 409 | `LimitExceededException` | no | `OrderNotPlaced`* |
| `daily_limit_exceeded` | 429 | `LimitExceededException` | no | `OrderNotPlaced`* |
| `rate_limited` | 429 | `RateLimitedException` | yes | `OrderOutcomeUnknown`* |
| `wallet_disabled` | 409 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `wallet_expired` | 409 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `business_subscription_required` | 409 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `account_inactive` | 403 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `currency_not_supported` | 422 | `ValidationFailedException` | no | `OrderNotPlaced`* |
| `card_unavailable` | 409 | `OutOfStockException` | no | `OrderNotPlaced`* |
| `quantity_unavailable` | 409 | `OutOfStockException` | no | `OrderNotPlaced`* |
| `price_changed` | 409 | `PriceChangedException` | no | `OrderNotPlaced`* |
| `reveal_not_allowed` | 409 | `AuthorizationException` | no | `OrderNotPlaced`* |
| `invoice_reveal_limit_exceeded` | 409 | `AnisApiException` | no | `OrderNotPlaced`* |
| `invitation_invalid` | 401 | `EnrollmentRefusedException` | no | `OrderNotPlaced`* |
| `challenge_expired` | 409 | `EnrollmentRefusedException` | no | `OrderNotPlaced`* |
| `key_proof_invalid` | 422 | `EnrollmentRefusedException` | no | `OrderNotPlaced`* |
| `key_duplicate` | 409 | `EnrollmentRefusedException` | no | `OrderNotPlaced`* |
| `dependency_unavailable` | 503 | `DependencyUnavailableException` | yes | `OrderOutcomeUnknown`* |
| `request_timeout` | 504 | `DependencyUnavailableException` | yes | `OrderOutcomeUnknown`* |
| `internal_error` | 500 | `DependencyUnavailableException` | yes | `OrderOutcomeUnknown`* |

* `OrderProcessing` is a 202 response, not an exception. `OrderNotPlaced` confirms no charge. `OrderOutcomeUnknown`
means resume the same id and exact request. A signer failure is a `RequestSigningException` thrown before sending;
nothing was sent. Reads throw a typed exception for verified refusals. Orders return refusal outcomes instead.

All SDK-specific failures implement `Anis\Partners\AnisPartnersException`; catch `AnisPartnersException` to catch
everything from this SDK. Key-document retrieval failures use `SigningKeysUnavailableException` and preserve their cause.

`validation_failed` does not name the field. Check your request against the route contract, including body size and
shape, exact decimal prices, identifiers, and bodyless reveal calls. Branch on the code rather than the message.

An answer that cannot be verified is discarded and raises `UnverifiableResponseException` on reads. Orders return
`OrderOutcomeUnknown`; use the same operation id to resume. The response content is never exposed.
