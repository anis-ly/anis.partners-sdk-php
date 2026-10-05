<?php

declare(strict_types=1);

namespace Anis\Partners\Internal;

/** Normalizes UUIDs to the one canonical representation used on the wire. */
final class Uuid
{
    /** Normalizes accepted UUID forms once so key identifiers have one stable wire spelling. */
    public static function canonical(string $value): string
    {
        if (preg_match('/\A(?:[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}|[0-9a-f]{32})\z/iD', $value) !== 1) {
            throw new \InvalidArgumentException('The key id must be a UUID.');
        }
        $compact = str_replace('-', '', strtolower($value));

        return substr($compact, 0, 8) . '-' . substr($compact, 8, 4) . '-' . substr($compact, 12, 4)
            . '-' . substr($compact, 16, 4) . '-' . substr($compact, 20);
    }
}
