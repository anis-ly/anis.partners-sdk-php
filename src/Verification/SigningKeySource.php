<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Supplies published response-verification keys, including an explicit refresh path. */
interface SigningKeySource
{
    /** Returns cached keys when available so each response does not fetch the same document. */
    public function get(): SigningKeySet;

    /** Fetches the key document after a key miss; the HTTP source does so at most once per 30 seconds so forged key ids cannot trigger repeated requests. */
    public function refresh(): SigningKeySet;
}
