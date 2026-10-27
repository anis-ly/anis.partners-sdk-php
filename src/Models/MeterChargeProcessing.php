<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says the meter charge was admitted and still needs a later read or an identical resume under the same operation id. */
final readonly class MeterChargeProcessing extends MeterChargeResult
{
    /** @param MeterCharge|null $read The latest full read when Anis answered one that is not final yet. */
    public function __construct(string $operationId, public int $retryAfterSeconds = 5, public ?string $location = null, public ?MeterCharge $read = null)
    {
        parent::__construct($operationId);
    }
}
