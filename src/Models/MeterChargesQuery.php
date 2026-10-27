<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Filters the meter charges of one wallet. */
final readonly class MeterChargesQuery
{
    public function __construct(
        public ?MeterChargeStatus $status = null,
        public ?string $meterNumber = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?string $cursor = null,
        public ?int $pageSize = null,
    ) {}
}
