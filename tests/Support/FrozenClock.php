<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private int $timestamp) {}

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
