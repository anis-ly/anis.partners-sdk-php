<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Searches the places a recipient field can take. */
final readonly class DirectRechargeLocationsQuery
{
    public function __construct(public ?string $search = null, public ?string $cursor = null) {}
}
