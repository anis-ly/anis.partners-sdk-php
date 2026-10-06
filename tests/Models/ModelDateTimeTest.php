<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\ModelData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelDateTimeTest extends TestCase
{
    #[Test]
    public function it_parses_rfc3339_offsets_and_returns_utc(): void
    {
        $date = ModelData::dateTime(['at' => '2026-10-05T12:34:56.1234567+03:00'], 'at');

        self::assertSame('2026-10-05T09:34:56.123456Z', $date?->format('Y-m-d\TH:i:s.u\Z'));
    }

    #[Test]
    public function it_refuses_free_form_or_impossible_dates(): void
    {
        foreach (['tomorrow', '2026-10-05T12:00:00', '2026-02-30T00:00:00Z'] as $value) {
            try {
                ModelData::dateTime(['at' => $value], 'at');
                self::fail('Invalid date-time must be refused: ' . $value);
            } catch (\UnexpectedValueException $exception) {
                self::assertStringContainsString('at', $exception->getMessage());
            }
        }
    }
}
