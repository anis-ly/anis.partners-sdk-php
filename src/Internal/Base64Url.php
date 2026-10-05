<?php

declare(strict_types=1);

namespace Anis\Partners\Internal;

/** Keeps protocol byte fields in the unpadded encoding Anis expects. */
final class Base64Url
{
    /** Encodes bytes for HTTP fields without padding, matching the format Partner signatures carry. */
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Decodes only non-empty unpadded base64url so malformed wire values cannot be silently accepted. */
    public static function decode(string $value): ?string
    {
        if ($value === '' || strlen($value) % 4 === 1 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false || self::encode($decoded) !== $value ? null : $decoded;
    }
}
