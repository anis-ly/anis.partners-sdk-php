<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies where a catalogue category's cards come from. */
enum CatalogueCategoryType: string
{
    case Unknown = 'unknown';
    case Local = 'local';
    case International = 'international';

    /** Maps new owner values to Unknown so a new category type does not break reads. */
    public static function parse(?string $value): self
    {
        return match (strtolower($value ?? '')) {
            'local' => self::Local,
            'international' => self::International,
            default => self::Unknown,
        };
    }
}
