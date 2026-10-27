<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries the closed direct recharge as the owner read states it, including a failed one with its refund. */
final readonly class DirectRechargeFinal extends DirectRechargeResult
{
    public function __construct(public DirectRecharge $recharge, public bool $replayed = false)
    {
        parent::__construct($recharge->operationId);
    }
}
