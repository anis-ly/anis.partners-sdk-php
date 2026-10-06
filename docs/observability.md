# Observability

The SDK uses the OpenTelemetry API scope `anis-ly/partners` and PSR-3 logging. It does not require a telemetry SDK. Instrumentation is best-effort: a host logger, tracer, or meter failure never changes an API result.

Each request span includes `anis.client`, `anis.route`, and `http.request.method`; it may also include `anis.operation_id`, `anis.request_id`, `http.response.status_code`, and `anis.error.code`. Operation and request ids stay on spans and logs, never on metric series.

Metrics use only bounded attributes: client name, route template, method, response status, refusal code, no-answer `error.type`, signature profile, verification failure, order outcome, or key-fetch reason. Order outcome metrics count `completed`, `processing`, `replayed`, and `unknown`; a final `not_placed` refusal is not counted as an order outcome.

A verified refusal is represented by `anis.error.code` and an error span status; it has no `error.type`. Event 1003 and the verification-failure counter are emitted only when an answer fails verification. Event 1007 is a warning for every sent request that ends without a usable answer and includes `elapsed_ms`; it is distinct from the verification-failure event. A signing or validation error before the HTTP client receives the request is reported as a not-sent error, not an unknown order.

| Event | Level | Meaning |
|---|---|---|
| 1000 | debug | Request signed |
| 1001 | debug | Verified response received |
| 1002 | warning | Verified Anis refusal |
| 1003 | error | Response verification failed |
| 1004 | info | Order completed, processing, or replayed |
| 1005 | info | Signing-key document fetched |
| 1006 | warning | Unknown signing key or optional cache write failed |
| 1007 | warning | Sent request ended without a usable answer |
| 1008 | warning | Order outcome remains unknown |

Log messages use PSR-3 placeholders and structured context, including `event_id` and `elapsed_ms` where applicable. Secret values are never included in logs, spans, metrics, or SDK exception messages.
