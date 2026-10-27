<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Filters the direct recharge products of one wallet. */
final readonly class DirectRechargeProductsQuery
{
    public function __construct(public ?DirectRechargeProductType $type = null, public ?string $cursor = null) {}
}
