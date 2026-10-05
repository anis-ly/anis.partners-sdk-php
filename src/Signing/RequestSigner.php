<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Adds the enrolled credential identifier needed to bind each signed request. */
interface RequestSigner extends P256Signer
{
    /** Returns the enrolled credential's canonical UUID so Anis can select its public key. */
    public function keyId(): string;
}
