<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says whether a quote amount is dinars or the product currency. */
enum DirectRechargeAmountIn: string
{
    case Lyd = 'lyd';
    case Fx = 'fx';
}
