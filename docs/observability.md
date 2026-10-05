# Observability

The SDK uses OpenTelemetry's global tracer and meter providers under the instrumentation scope `anis-ly/partners`.
Install and configure an OpenTelemetry SDK/exporter in your host to collect signals; the PHP package depends only on
the OpenTelemetry API. A PSR-3 logger can be passed to the client factory.

```php
$client = AnisPartnersClient::create($options, $signer, logger: $logger);
```

## Traces

Each API call creates a client span named `anis.partners {route}`. Attributes include `anis.client` (`default`),
`anis.route` (a route template), `http.request.method`, and, for orders, `anis.operation_id`. A received response adds
`http.response.status_code` and `anis.request_id`; a refusal adds `anis.error.code`, and an unusable answer adds
`error.type`.

## Metrics

| Instrument | Type | Attributes |
|---|---|---|
| `anis.partners.request.duration` | histogram in ms | client, route template, method, optional status, error type, error code |
| `anis.partners.signature.duration` | histogram in ms | signature profile |
| `anis.partners.response.verification.failures` | counter | verification failure reason |
| `anis.partners.order.outcomes` | counter | client, outcome, optional error type |
| `anis.partners.signing_keys.fetches` | counter | fetch reason: `first-use`, `expired`, or `refresh` |

Route templates avoid high-cardinality wallet and card identifiers. `unknown` order outcomes need attention and safe
recovery with the same operation id.

## Log events

The SDK writes PSR-3 records with a stable `event_id` in context:

| Event id | Level | Event |
|---:|---|---|
| 1000 | debug | Request signed |
| 1001 | debug | Response received |
| 1002 | warning | Request refused |
| 1003 | error | Response discarded after verification failure |
| 1004 | info | Order outcome |
| 1005 | info | Signing keys fetched |
| 1006 | warning | Unknown response signing key |
| 1007 | warning | Request ended without a usable answer |
| 1008 | warning | Order outcome unknown; resume the same id |

Private keys, signatures, signature bases, signature-input values, nonces, enrollment tokens, vouchers, and serial
numbers are not emitted as telemetry fields. Avoid logging credential objects in application code as well.
