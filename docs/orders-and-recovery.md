# Orders and recovery

An order can charge a wallet and release credentials. Persist the operation id and exact request in your durable
store before calling `create()`. On a timeout, resume with that same id and body; never start a new operation until
you know the earlier one was not placed.

## Exact wallet price

Use the card's `unitPrice`, which is the price for the selected wallet. Build totals with `Money`; it stores integer
thousandths and refuses amounts with more than three decimal places.

```php
use Anis\Partners\Models\CreateOrderRequest;

$unitPrice = $card->unitPrice ?? throw new RuntimeException('This card has no price for the selected wallet.');
$request = new CreateOrderRequest($card->id, 2, $unitPrice, $unitPrice->multiply(2), 'sale-123');
$operationId = '6f5c918a-1d44-4f7e-ae7a-cf37083c9f31';
$orderStore->recordIntent($operationId, $walletId, $request); // commit before create()
$result = $client->orders()->create($walletId, $operationId, $request);
```

The SDK checks quantity, positive unit price, currencies, and multiplication before sending. It does not generate an
operation id because a fresh id after a lost answer could place a second order.

## Five outcomes

| Result | Meaning | Next step |
|---|---|---|
| `OrderCompleted` | The order completed; credentials may be present only on this first completion. | Store credentials immediately in protected storage. If `codesWithheld` is true, do not buy again; contact support. |
| `OrderProcessing` | The order was accepted and is still processing. | Wait `retryAfterSeconds`, then resume the same id and body. |
| `OrderReplayed` | An earlier completion was already delivered. | No credentials are repeated. Use an authorized reveal if needed. |
| `OrderNotPlaced` | Anis confirms that this operation was not charged. | Correct the refusal; a genuinely new purchase uses a new id. |
| `OrderOutcomeUnknown` | No verified final outcome is available. | Resume the same id and exact request after `suggestedDelaySeconds`. |

A signer failure throws `RequestSigningException` before the HTTP request is sent. A lost connection, an unreadable
answer, or a response that cannot be verified is an unknown result. On a read, an unverifiable response is discarded
and raised as `UnverifiableResponseException`; it never exposes the response body.

`orders()->get($operationId)` reads state only and does not return credentials. A replay also withholds credentials.
Use `ownedCards()->revealInvoice($walletId, $invoiceId)` only when the application has the `cards:reveal` permission.

## Credential-once rule

Treat credentials as one-time response data. Persist them before work that may fail, and do not serialize them into
logs. A completed order with no released codes is still paid and complete; do not submit it again.

For typed refusal guidance see [Errors](errors.md). For every endpoint and required permission see
[Routes and permissions](routes-and-permissions.md).
