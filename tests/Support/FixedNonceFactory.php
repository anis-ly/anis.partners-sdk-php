<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use Anis\Partners\Signing\NonceFactory;

final class FixedNonceFactory implements NonceFactory
{
    public function __construct(private readonly string $nonce) {}

    public function create(): string
    {
        return $this->nonce;
    }
}
