<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries the closed meter charge as the owner read states it, including a failed one with its refund. */
final readonly class MeterChargeFinal extends MeterChargeResult
{
    public function __construct(public MeterCharge $charge, public bool $replayed = false)
    {
        parent::__construct($charge->operationId);
    }
}
