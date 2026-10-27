<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Filters the direct recharges of one wallet. */
final readonly class DirectRechargesQuery
{
    public function __construct(
        public ?DirectRechargeStatus $status = null,
        public ?string $productId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?string $cursor = null,
        public ?int $pageSize = null,
    ) {}
}
