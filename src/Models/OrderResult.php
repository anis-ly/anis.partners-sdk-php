<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Makes every order outcome explicit so a missing credential list cannot hide a completed sale. */
abstract readonly class OrderResult
{
    protected function __construct(public string $operationId) {}

    /** Keeps future credential-bearing results safe in native object inspection. */
    public function __debugInfo(): array
    {
        return ['operationId' => $this->operationId, 'details' => '<redacted>'];
    }
}
