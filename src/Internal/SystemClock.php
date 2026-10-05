<?php

declare(strict_types=1);

namespace Anis\Partners\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** Provides UTC system time for protocol freshness checks. */
final class SystemClock implements ClockInterface
{
    /** Supplies UTC time so signature-age checks do not depend on the machine's local zone. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
