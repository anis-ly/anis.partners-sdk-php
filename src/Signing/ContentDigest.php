<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Builds the body digest Anis verifies before accepting a signed request. */
final class ContentDigest
{
    /** The two-byte JSON object used where the contract defines an empty object body. */
    public const EMPTY_OBJECT = '{}';

    /** Returns the RFC 9530 SHA-256 structured-field value over the exact transmitted body bytes. */
    public static function of(string $body): string
    {
        return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    }
}
