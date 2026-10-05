<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use Anis\Partners\Verification\SigningKeySet;
use Anis\Partners\Verification\SigningKeySource;

final class StaticSigningKeySource implements SigningKeySource
{
    public int $getCalls = 0;
    public int $refreshCalls = 0;

    public function __construct(public readonly SigningKeySet $keys) {}

    public function get(): SigningKeySet
    {
        $this->getCalls++;

        return $this->keys;
    }

    public function refresh(): SigningKeySet
    {
        $this->refreshCalls++;

        return $this->keys;
    }
}
