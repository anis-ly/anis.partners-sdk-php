# Webhooks

Anis tells your application when a meter charge or a direct recharge changes by sending a signed `POST` to the one
HTTPS address you set. This guide covers the address, the verification you must do, and what to do with a delivery.

## Set the address

```php
use Anis\Partners\Models\SetWebhookEndpointRequest;

$endpoint = $client->webhookEndpoint()->set(new SetWebhookEndpointRequest('https://pos.example.ly/hooks/anis'));
// status is pendingVerification until your address passes verification.
$current = $client->webhookEndpoint()->get(); // url, status and generation; all three are always present, null when nothing is set.
```

The address must use HTTPS on port 443, have a host name that contains a dot (not an IP address), no user name and no fragment, and be at
most 2048 characters; otherwise Anis answers `webhook_endpoint_invalid`. The status is `notConfigured`,
`pendingVerification`, `verified` or `disabled`. Setting the same address again keeps its state; a different address
must be verified again. `test()` and `webhookEvents()->redeliver()` need a verified, enabled endpoint, otherwise Anis answers
`webhook_endpoint_unverified`.

## Verify, then read

Capture the raw bytes before any JSON middleware touches them, then verify. Never take the expected address or
application from the request. Capture the actual method and raw path/query before routing rewrites; combine the
received path/query with a trusted public origin configured for your deployment. Keep that actual target separate from
`$configuredUrl`, the expected delivery address. Set `WEBHOOK_ORIGIN` for the receiving sample.

```php
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\PartnerWebhookVerifier;
use Anis\Partners\Verification\UnverifiableWebhookException;
use Anis\Partners\Verification\VerifiableWebhook;
use GuzzleHttp\Client;
use Http\Discovery\Psr17FactoryDiscovery;

$configuredUrl = 'https://pos.example.ly/hooks/anis';
$applicationId = '9b2d1241-413e-4ae8-ae32-b15c58dbe101';
$http = new Client(['timeout' => 10, 'allow_redirects' => false]);
$keys = new HttpSigningKeySource($http, Psr17FactoryDiscovery::findRequestFactory(), 'https://partners.example', 300);
$verifier = new PartnerWebhookVerifier($keys, $configuredUrl, $applicationId);

$headers = [];
$received = getallheaders();
foreach (is_array($received) ? $received : [] as $name => $value) {
    if (is_string($name) && is_string($value)) {
        $headers[$name] = $value;
    }
}
// Trusted public origin of this deployment; never use Host or forwarded request headers.
$trustedOrigin = 'https://pos.example.ly';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$requestTarget = $_SERVER['REQUEST_URI'] ?? '';
if (!is_string($method) || !is_string($requestTarget) || !str_starts_with($requestTarget, '/')) {
    http_response_code(400);
    exit;
}
$webhook = new VerifiableWebhook($method, $trustedOrigin . $requestTarget, $headers, (string) file_get_contents('php://input'));

try {
    $envelope = $verifier->verifyAndParse($webhook);
} catch (UnverifiableWebhookException $error) {
    error_log('Webhook refused: ' . $error->failure()->value);
    http_response_code(400);
    exit;
}
```

`verify()` only proves the delivery; `verifyAndParse()` also returns the `WebhookEnvelope` and reads nothing from a
delivery that failed. The exception carries the reason only, never the body, headers or signature. The
`VerifiableWebhook` hides its body and header values from dumps.

## The envelope

`WebhookEnvelope` carries `id`, `type`, `occurredAt`, `applicationId` and `data`. For the eight operational types
`data` is the typed `MeterCharge` or `DirectRecharge` read as it stood at delivery:

| Type | Typed data |
|---|---|
| `meterCharge.succeeded`, `meterCharge.failed` | `MeterCharge` |
| `directRecharge.paid`, `.paymentFailed`, `.suspended`, `.completed`, `.rejected`, `.partiallyRefunded` | `DirectRecharge` |

The type says what triggered the delivery; **`data.status` is the truth**. A `directRecharge.suspended` delivery sent
after you corrected the recipient can legitimately show `inProgress`. A type this SDK does not know is accepted after
verification with `WebhookEventType::Unknown`, its raw `rawType` and its raw `rawData`; do not fail on it. `dataRaw` is the data value exactly as received.
Partial refunds occur only if partial refunds are enabled for the product, so `directRecharge.partiallyRefunded` may never arrive.

## Deduplicate durably

The event `id` is the same on every retry and redelivery; the attempt number is not a dedupe key. After verification
and before you act or answer, record the id in your own durable store with an atomic insert; a duplicate means you
already handled it. The SDK keeps no cache of its own, so a restart cannot lose your record.

## Answer the verification challenge

When you set or change the address, Anis sends a signed `webhook_endpoint.verification` delivery whose data holds a
random `challenge` and the `generation`. After it verifies, answer with a 2xx within 10 seconds and exactly the JSON
`{"challenge":"<the same value>"}`. Never echo a challenge from a request you have not verified.

```php
use Anis\Partners\Models\WebhookEnvelope;
use Anis\Partners\Models\WebhookEventType;

function answerChallenge(WebhookEnvelope $envelope): void
{
    if ($envelope->type !== WebhookEventType::EndpointVerification) {
        return;
    }
    $challenge = $envelope->rawData['challenge'] ?? null;
    header('Content-Type: application/json');
    echo json_encode(['challenge' => is_string($challenge) ? $challenge : ''], JSON_THROW_ON_ERROR);
    exit;
}
```

`webhook_endpoint.test` deliveries (from `webhookEndpoint()->test()`) carry a `message` and need no special answer beyond a 2xx.

## Missed deliveries

Any 2xx within 10 seconds counts as delivered; redirects count as failures. Anis retries up to 12 times over about three
days. List deliveries and ask for one again:

```php
use Anis\Partners\Models\WebhookDeliveryStatus;
use Anis\Partners\Models\WebhookEventsQuery;

foreach ($client->webhookEvents()->getWebhookEvents(new WebhookEventsQuery(WebhookDeliveryStatus::Failed)) as $event) {
    $client->webhookEvents()->redeliver($event->id); // Same id and body; sent once, never replayed for you.
}
```

Each summary has `id`, `type`, `status`, `attempts`, `lastAttemptAt`, `nextAttemptAt`, `lastResponseCode` and
`lastFailureKind`; the last four can be null. The list holds no operation data. Failed deliveries stay redeliverable for 30 days.
`test()` and `redeliver()` are signed changes that are not repeated for you: after a lost answer read the delivery list first.

## Your server

Return a 2xx quickly and do the work afterwards. Allow [Anis's webhook address](https://developers.anis.ly/docs/webhooks#where-webhooks-come-from) to reach your address, answer over
TLS 1.2 or later, and do not redirect. See the receiving example in `samples/webhook-receiver/receive.php`.
