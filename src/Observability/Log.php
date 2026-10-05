<?php

declare(strict_types=1);

namespace Anis\Partners\Observability;

use Psr\Log\LoggerInterface;

/** Centralizes structured events so secrets cannot slip into interpolated messages. */
final class Log
{
    /** Writes the stable event number with structured fields for partner support. */
    /** @param array<string, mixed> $context */
    public static function write(LoggerInterface $logger, string $level, string $message, int $eventId, array $context = []): void
    {
        try {
            $logger->log($level, $message, ['event_id' => $eventId] + $context);
        } catch (\Throwable) {
        }
    }
}
