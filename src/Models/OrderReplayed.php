<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents an already delivered result without making the credentials available a second time. */
final readonly class OrderReplayed extends OrderResult
{
    /** Keeps these public partner values stable after construction. */
    public function __construct(public Order $order)
    {
        parent::__construct($order->operationId);
    }
}
