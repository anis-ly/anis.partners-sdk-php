<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Makes every direct recharge outcome explicit so an unresolved create is never mistaken for a refusal. */
abstract readonly class DirectRechargeResult
{
    protected function __construct(public string $operationId) {}
}
