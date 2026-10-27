<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Makes every meter charge outcome explicit so an unresolved create is never mistaken for a refusal. */
abstract readonly class MeterChargeResult
{
    protected function __construct(public string $operationId) {}
}
