<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says Anis refused the direct recharge before any money moved; a new attempt needs a new operation id. */
final readonly class DirectRechargeNotPlaced extends DirectRechargeResult
{
    public function __construct(string $operationId, public \Anis\Partners\Errors\AnisApiException $refusal)
    {
        parent::__construct($operationId);
    }
}
