<?php

declare(strict_types=1);

namespace Anis\Partners\Internal;

/** Parses bounded delta-seconds so unsupported waits fall back to the SDK default. */
final class RetryAfter
{
    public static function parse(?string $value): ?int
    {
        if ($value === null || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }
        $canonical = ltrim($value, '0');
        if ($canonical === '') {
            return 0;
        }
        if (strlen($canonical) > 10) {
            return null;
        }
        $seconds = (int) $canonical;

        return $seconds <= 2147483647 ? $seconds : null;
    }
}
