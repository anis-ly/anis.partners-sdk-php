<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private float $monotonicNanoseconds = 0.0;

    public function __construct(private int $timestamp, private int $microseconds = 0) {}

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->timestamp . sprintf('.%06d', $this->microseconds)))->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(int $seconds, int $microseconds = 0): void
    {
        $this->monotonicNanoseconds += max(0, $seconds * 1_000_000_000 + $microseconds * 1000);
        $fraction = $this->microseconds + $microseconds;
        $this->timestamp += $seconds + intdiv($fraction, 1_000_000);
        $this->microseconds = $fraction % 1_000_000;
        if ($this->microseconds < 0) {
            $this->timestamp--;
            $this->microseconds += 1_000_000;
        }
    }

    public function monotonicNow(): float
    {
        return $this->monotonicNanoseconds;
    }
}
