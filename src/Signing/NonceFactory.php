<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Creates one-use values for mutation requests so a captured signature cannot be replayed. */
interface NonceFactory
{
    /** Returns a fresh nonce that this process has not returned before. */
    public function create(): string;
}
