<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

use Anis\Partners\Internal\Uuid;

/** Associates a P-256 key with its enrolled credential ID for request signing. */
final class KeyedSigner implements RequestSigner
{
    private readonly string $canonicalKeyId;

    /**
     * Binds the key to a valid credential UUID so each request identifies its enrolled key.
     *
     */
    public function __construct(private readonly P256Signer $signer, string $keyId)
    {
        $this->canonicalKeyId = Uuid::canonical($keyId, 'key id');
    }

    /** Returns the normalized credential ID included in the signature parameters. */
    public function keyId(): string
    {
        return $this->canonicalKeyId;
    }

    /** Signs the exact base bytes without changing them. */
    public function sign(#[\SensitiveParameter] string $data): string
    {
        return $this->signer->sign($data);
    }
}
