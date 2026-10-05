<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Supplies published response-verification keys, including an explicit refresh path. */
interface SigningKeySource
{
    /** Returns cached keys when available so each response does not fetch the same document. */
    public function get(): SigningKeySet;

    /** Fetches the latest key document after a key miss so normal key rotation can complete. */
    public function refresh(): SigningKeySet;
}
