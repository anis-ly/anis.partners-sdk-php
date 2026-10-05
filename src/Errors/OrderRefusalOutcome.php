<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Distinguishes a closed refusal from an order that must be safely resumed. */
enum OrderRefusalOutcome: string
{
    case NotPlaced = 'not_placed';
    case Unknown = 'unknown';
}
