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
- **`OrderOutcomeUnknown`**: the purchase may still have completed. Keep the original id and request and resume after `suggestedDelaySeconds`.

Five result types make the credential and payment state explicit; do not infer it from a nullable field or message text.

## Recovery is a signed POST

`get()` reports state only; it does not dispatch or recover an order. Recovery is `resume()`, which sends the exact order body again with the same operation id and a fresh request signature. Never wrap the SDK's HTTP client in a retry layer that resends a request: it replays the same signed bytes and nonce, which Anis rejects as `replay_detected`. After a timeout, connection loss, unverifiable answer, or access refusal, call `resume()` with the stored intent.

A request-signing or local validation exception means nothing was sent. Correct the input or signer before trying again. It is not an unknown order.

`Retry-After` is honored from 0 through 2,147,483,647 seconds. Missing or out-of-range values use the operation's default delay. Access-door refusals use 60 seconds unless Anis supplies a valid `Retry-After`.

## Recovery exhausted

An order with status `recoveryExhausted` is **not failed**; it may have completed. Never submit it under a new id. Continue resuming the same id slowly, in minutes rather than seconds. Every resume asks Anis to check with the owner, and one may return the completed order with its credentials. Contact support@anis.ly with the operation id so Anis can resolve it.

A resume that returns `OrderCompleted` may contain the credentials even if an earlier attempt did not. Store them immediately. A later replay contains no credentials; a reveal is a separate, explicitly permissioned operation.


## HTTP client timeouts

PSR-18 does not define a portable timeout option. Configure a finite connection and request timeout on the HTTP client you pass to the SDK; a timeout after the request is handed to that client leaves the order outcome unknown, so recover with `resume()` and the stored intent. Disable redirect following for signed requests.
