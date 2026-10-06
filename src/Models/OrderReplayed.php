<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents an already delivered result without making the credentials available a second time. */
final readonly class OrderReplayed extends OrderResult
{
    public function __construct(#[\SensitiveParameter] public Order $order)
    {
        parent::__construct($order->operationId);
    }
}
