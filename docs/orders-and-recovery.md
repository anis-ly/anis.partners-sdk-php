# Orders and recovery

An order can charge a wallet and release credentials. Persist the operation id and exact request in your durable store **before** calling `create()`. A lost answer plus a new id can create a second purchase.

## Send the wallet's exact price

Use `unitPrice`, which is the amount this wallet pays now. Display prices can differ. `Money` stores thousandths as integers and accepts no more than three decimal places; it never rounds. Its JSON form is the wire object `{"amount":"10.500","currency":"LYD"}`. It can also be stored by your application, so protect any record containing credentials or account data.

```php
$card = $client->catalogue()->listCardsPage($walletId, $subcategoryId)->items[0] ?? null;
if (!$card instanceof \Anis\Partners\Models\CatalogueCard || $card->unitPrice === null) {
    throw new RuntimeException('The catalogue page has no orderable card price.');
}
$quantity = 2;
$request = new CreateOrderRequest(
    $card->id,
    $quantity,
    $card->unitPrice,
    $card->unitPrice->multiply($quantity),
);

$operationId = $uuidGenerator->v4(); // A new random UUID v4 for each purchase.
$journal->save($operationId, $walletId, $request); // Before create().
$outcome = $client->orders()->create($walletId, $operationId, $request);
```

The total must equal unit price multiplied by quantity, both currencies must match, quantity must be positive, and unit price must be greater than zero. These checks happen before any request is sent. `useAllowedDebt` defaults to `false`; set it to `true` only when you intend to spend the account's allowed debt.

## The five outcomes

- **`OrderCompleted`**: the sale completed. Store released credentials securely as the first action. `codesWithheld` means the order completed without releasing any codes; never buy it again, and contact support@anis.ly with the operation id.
- **`OrderProcessing`**: Anis accepted work and asks for a later signed resume. Wait `retryAfterSeconds` (default 5 seconds) and use the same operation id and exact request.
- **`OrderReplayed`**: this operation already completed. Credentials are not returned again. Use the invoice reveal route only if your application has the required permission.
- **`OrderNotPlaced`**: Anis confirmed that nothing was bought. Fix the refusal and start a new purchase with a fresh id.
  A spending-cap refusal (`spending_limit_exceeded`, HTTP 429, `LimitExceededException`) is one: the order would have
  taken the application or wallet past its spending cap for the current window. `retryAfter` is the number of seconds
  until the window ends (windows follow UTC); after it, place a new order with a new id — never resume the refused one.
- **`OrderOutcomeUnknown`**: the purchase may still have completed. Keep the original id and request and resume after `suggestedDelaySeconds`.

Five result types make the credential and payment state explicit; do not infer it from a nullable field or message text.

## Recovery is a signed POST

`get()` reports state only; it does not dispatch or recover an order. Recovery is `resume()`, which sends the exact order body again with the same operation id and a fresh request signature. Never wrap the SDK's HTTP client in a retry layer that resends a request: it replays the same signed bytes and nonce, which Anis rejects as `replay_detected`. After a timeout, connection loss, unverifiable answer, or access refusal, call `resume()` with the stored intent.

A request-signing or local validation exception means nothing was sent. Correct the input or signer before trying again. It is not an unknown order.

`Retry-After` is honored from 0 through 2,147,483,647 seconds. Missing or out-of-range values use the operation's default delay. Access-door refusals use 60 seconds unless Anis supplies a valid `Retry-After`.

## Meter charges and direct recharge

`meterCharges()->create()` and `directRecharges()->create()` follow the same safe-recovery rules as an order: you
choose and store the operation id and the exact request first, the id travels only as the `Idempotency-Key`, and
`resume()` repeats the same request under the same id with a fresh signature. It never makes a new id.

- **`...Processing`**: Anis accepted the work (its answer is exactly the operation id, the operation type and
  `processing`) or returned a read that is not final yet. Wait `retryAfterSeconds`, then `resume()` or `get()`.
  A direct recharge status of `awaitingPayment`, `pending`, `inProgress`, `suspendedDataError` or `rejectedAwaitingRefund` is not final.
  A meter charge that failed while its refund is `pending` is not final either: money was taken and the refund is on its
  way, so read again (`get()`) or wait for the `meterCharge.failed` notification.
- **`...Final`**: the closed read. A meter charge that failed and was refunded (`refund->status` `refunded`) or never
  charged (`notCharged`) is a final result with its failure and refund, not an error. A direct recharge is final when it is `completed`, `paymentFailed` or `rejectedWithRefund`.
- **`...NotPlaced`**: Anis confirmed nothing was bought: a definitive refusal of the first create, or a recorded replay
  of one. A new attempt needs a new id.
- **`...OutcomeUnknown`**: a timeout, a lost or unreadable answer, an answer that fails verification, a status this SDK
  does not know, an answer for another operation, an access refusal while resuming, or `idempotency_conflict`.
  Resume the original id and request.

`idempotency_conflict` on a create or a resume is never `...NotPlaced`. It does not say the original request failed: it
may already have been accepted and charged. The result is `...OutcomeUnknown` with the typed refusal in `cause`; read the
operation by its id with `get()` before any new attempt. The SDK never makes a second purchase under another id.
`get()` returns the full read, or the three-member processing answer while Anis has not yet stored the operation.

`get()` can also answer the recorded refusal of the create: a signed 409 or 422 with `Idempotency-Replayed: true`. It
raises the same typed `AnisApiException` as a refused create (`errorCode`, `status`, `isReplayed`) and is the definitive
answer for that id. For `insufficient_balance`, `price_changed` and the other refusals nothing was charged. A 409
`idempotency_conflict` on `get()` means no operation of your application exists under that id.

A recipient correction (`updateRecipient()`), an endpoint change (`webhookEndpoint()->set()`), a test and a redelivery
are signed changes, not purchases. They are sent once and never replayed for you. After a lost answer, read the
direct recharge, the endpoint or the delivery list before deciding to send the change again.

`updateRecipient()` returns `DirectRechargeProcessing`, the same client result as `create()` returns for a verified
202. Both expose `operationId`, `retryAfterSeconds` and `location`, with `read` null for the acknowledgement. They use
the same Retry-After parser and default to 5 seconds when the header is absent or unsupported. Recipient PATCH also
requires the operation's verified Location header. Its wire body contains exactly `operationId`,
`operationType: directRecharge` and `status: processing`; the strict `DirectRechargeAccepted` wire model is used
internally and remains the model returned by `get()` for a GET 202. This acknowledges the correction without a direct recharge read.
Read later with `get()` or wait for a `directRecharge.*` webhook. An immediate read may still show `suspendedDataError`;
neither its status nor matching recipient values prove that this correction has been projected.
A missing/wrong Location, malformed acknowledgement or unexpected successful status raises `MalformedResponseException`.
Empty answers, failed signature verification, transport failures and API refusals retain their normal exceptions.
A lost, unreadable or unverifiable answer leaves the correction uncertain; read before deciding whether to submit
another correction. There is no automatic retry or read. An unchanged correction that already applied can be refused as `direct_recharge_not_suspended`.

```php
use Anis\Partners\Models\DirectRechargeRecipientInput;
use Anis\Partners\Models\UpdateDirectRechargeRecipientRequest;

$operationId = '7d4e1a90-5b2c-4e7f-8a13-0c9d6e2b4f81'; // The existing paused recharge's operation id.
$acknowledgement = $client->directRecharges()->updateRecipient(
    $operationId,
    new UpdateDirectRechargeRecipientRequest([new DirectRechargeRecipientInput('recipient_number', '01098765432')]),
);
$readPath = $acknowledgement->location; // The same location accessor as a 202 create.
$retryDelay = $acknowledgement->retryAfterSeconds; // Wait this long before reading; read is null.
// Read later with get($acknowledgement->operationId), or wait for its webhook. Do not replay the correction automatically.
```

Persist the request before the call. The console samples keep it as JSON and rebuild it with `fromArray()`:

```php
use Anis\Partners\Models\CreateDirectRechargeRequest;
use Anis\Partners\Models\DirectRechargeAmountIn;
use Anis\Partners\Models\DirectRechargeFinal;
use Anis\Partners\Models\DirectRechargeProcessing;
use Anis\Partners\Models\DirectRechargeRecipientInput;
use Anis\Partners\Models\Money;

$productId = '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046';
$quote = $client->directRechargeProducts()->quote($walletId, $productId, '1500.000', DirectRechargeAmountIn::Fx);
$request = new CreateDirectRechargeRequest(
    $productId,
    $quote->payout->amount,
    $quote->total,
    [new DirectRechargeRecipientInput('recipient_number', '01012345678')],
    'Family support',
);
$operationId = $uuidGenerator->v4();
// Store $operationId and $request->toJson() durably here, before the call.
$result = $client->directRecharges()->create($walletId, $operationId, $request);

if ($result instanceof DirectRechargeProcessing) {
    // Wait, then resume() or get(). A paused recharge needs updateRecipient() with corrected details.
} elseif ($result instanceof DirectRechargeFinal) {
    $status = $result->recharge->status;
}
```

## Recovery exhausted

An order with status `recoveryExhausted` is **not failed**; it may have completed. Never submit it under a new id. Continue resuming the same id slowly, in minutes rather than seconds. Every resume asks Anis to check with the owner, and one may return the completed order with its credentials. Contact support@anis.ly with the operation id so Anis can resolve it.

A resume that returns `OrderCompleted` may contain the credentials even if an earlier attempt did not. Store them immediately. A later replay contains no credentials; a reveal is a separate, explicitly permissioned operation.


## HTTP client timeouts

PSR-18 does not define a portable timeout option. Configure a finite connection and request timeout on the HTTP client you pass to the SDK; a timeout after the request is handed to that client leaves the order outcome unknown, so recover with `resume()` and the stored intent. Disable redirect following for signed requests.
