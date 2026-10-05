<?php

declare(strict_types=1);

namespace Anis\Partners;

/** Selects the language Anis uses for localized presentation without changing API decisions. */
enum AcceptLanguage: string
{
    /** Leaves presentation language to Anis. */
    case Unspecified = 'Unspecified';
    /** Requests Arabic presentation. */
    case Arabic = 'Arabic';
    /** Requests English presentation. */
    case English = 'English';
}
