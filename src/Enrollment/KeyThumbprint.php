<?php

declare(strict_types=1);

namespace Anis\Partners\Enrollment;

use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Verification\PartnerJwk;

/** Computes the stable public-key identity Anis returns during credential enrollment. */
final class KeyThumbprint
{
    /** Hashes the canonical public JWK members so both sides derive the same key identity. */
    public static function compute(PartnerJwk $publicJwk): string
    {
        if ($publicJwk->kty !== 'EC' || $publicJwk->crv !== 'P-256') {
            throw new \InvalidArgumentException('The key must be an EC P-256 public JWK.');
        }
        $x = self::coordinate($publicJwk->x, 'x');
        $y = self::coordinate($publicJwk->y, 'y');
        $canonical = '{"crv":"P-256","kty":"EC","x":"' . $x . '","y":"' . $y . '"}';

        return Base64Url::encode(hash('sha256', $canonical, true));
    }

    private static function coordinate(?string $value, string $member): string
    {
        $decoded = $value === null ? null : Base64Url::decode($value);
        if ($decoded === null || strlen($decoded) !== 32) {
            throw new \InvalidArgumentException("The JWK member '{$member}' is not a 32-byte base64url coordinate.");
        }

        return $value;
    }
}
