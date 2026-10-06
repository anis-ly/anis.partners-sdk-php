<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

use Anis\Partners\Errors\AnisApiException;

/** Represents a closed refusal where Anis confirms nothing was bought or charged. */
final readonly class OrderNotPlaced extends OrderResult
{
    public function __construct(string $operationId, public AnisApiException $refusal)
    {
        parent::__construct($operationId);
    }
}
