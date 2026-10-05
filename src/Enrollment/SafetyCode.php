<?php

declare(strict_types=1);

namespace Anis\Partners\Enrollment;

use Anis\Partners\Internal\Base64Url;

/** Derives the short value partners compare to confirm they enrolled the intended key. */
final class SafetyCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Maps the thumbprint's leading bits to a readable code that can be compared out of band. */
    public static function fromThumbprint(string $thumbprint): string
    {
        $digest = Base64Url::decode($thumbprint);
        if ($digest === null || strlen($digest) !== 32) {
            throw new \InvalidArgumentException('A thumbprint must be the base64url encoding of 32 bytes.');
        }
        $code = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0; $i < 10; $i++) {
            $buffer = ($buffer << 8) | ord($digest[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $code .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }

        return implode('-', str_split($code, 4));
    }

    /**
     * Compares a partner-entered code or thumbprint without a timing-dependent string comparison.
     * @internal
     */
    public static function matches(?string $entered, string $thumbprint): bool
    {
        $raw = trim($entered ?? '');
        $candidate = strtoupper(str_replace([' ', '-'], '', $raw));
        $candidate = strtr($candidate, ['O' => '0', 'I' => '1', 'L' => '1']);
        $expectedCode = str_replace('-', '', self::fromThumbprint($thumbprint));

        return strlen($candidate) === 16
            ? hash_equals($expectedCode, $candidate)
            : hash_equals($thumbprint, $raw);
    }
}
