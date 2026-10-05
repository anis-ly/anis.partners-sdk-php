<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents an admitted order that still needs a later signed resume with the same operation ID. */
final readonly class OrderProcessing extends OrderResult
{
    /** Keeps these public partner values stable after construction. */
    public function __construct(public Order $order, public int $retryAfterSeconds = 5, public ?string $location = null)
    {
        parent::__construct($order->operationId);
    }
}
