<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

use Anis\Partners\Internal\Base64Url;

/** Creates cryptographically random nonces for signed mutations. */
final class RandomNonceFactory implements NonceFactory
{
    /** Returns 128 random bits in unpadded base64url for the Nonce field. */
    public function create(): string
    {
        return Base64Url::encode(random_bytes(16));
    }
}
