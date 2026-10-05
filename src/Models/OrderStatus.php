<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents the reported order state while allowing Anis to add future states safely. */
enum OrderStatus: string
{
    case Unknown = 'unknown';
    case Processing = 'processing';
    case RecoveryExhausted = 'recoveryExhausted';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Resolves known wire spellings case-insensitively and keeps unknown states readable. */
    public static function parse(?string $value): self
    {
        return match (strtolower($value ?? '')) {
            'processing' => self::Processing,
            'recoveryexhausted' => self::RecoveryExhausted,
            'completed' => self::Completed,
            'failed' => self::Failed,
            default => self::Unknown,
        };
    }
}
