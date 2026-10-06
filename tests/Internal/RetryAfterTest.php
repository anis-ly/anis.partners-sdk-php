<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Internal;

use Anis\Partners\Internal\RetryAfter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    #[Test]
    public function it_accepts_zero_and_the_signed_32_bit_upper_bound(): void
    {
        self::assertSame(0, RetryAfter::parse('0'));
        self::assertSame(2147483647, RetryAfter::parse('2147483647'));
        self::assertSame(5, RetryAfter::parse('05'));
    }

    #[Test]
    public function it_rejects_values_outside_delta_seconds(): void
    {
        foreach ([null, '', '-1', '2147483648', '999999999999999999999', '1.5', ' 1', '05s'] as $value) {
            self::assertNull(RetryAfter::parse($value));
        }
    }
}
