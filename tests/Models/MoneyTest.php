<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_reads_and_writes_amounts_at_three_places(): void
    {
        $money = Money::fromArray([
            'amount' => '21',
            'currency' => 'LYD',
            'asOf' => '2026-09-19T11:00:00+03:00',
        ]);

        self::assertSame(21000, $money->thousandths);
        self::assertSame('21.000', $money->amount());
        self::assertSame('LYD', $money->currency);
        self::assertSame('2026-09-19T08:00:00+00:00', $money->asOf?->format(DATE_ATOM));
        self::assertSame(
            '{"amount":"21.000","currency":"LYD","asOf":"2026-09-19T08:00:00Z"}',
            $money->toJson(),
        );
    }

    #[Test]
    public function it_refuses_a_json_number_as_the_amount(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Money::fromArray(['amount' => 10.5, 'currency' => 'LYD']);
    }

    #[Test]
    public function it_refuses_a_float_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        eval('declare(strict_types=0); \\Anis\\Partners\\Models\\Money::of(1.25, "LYD");');
    }

    #[Test]
    public function it_refuses_an_integer_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        eval('declare(strict_types=0); \\Anis\\Partners\\Models\\Money::of(1, "LYD");');
    }

    #[Test]
    public function it_multiplies_exactly_and_clears_the_balance_timestamp(): void
    {
        $money = new Money(10500, 'LYD', new \DateTimeImmutable('2026-09-19T08:00:00Z'));
        $total = $money->multiply(3);

        self::assertSame(31500, $total->thousandths);
        self::assertSame('31.500', $total->amount());
        self::assertNull($total->asOf);
    }

    #[Test]
    public function it_refuses_amounts_with_more_than_three_decimal_places(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Anis amounts have at most three decimal places');
        Money::of('0.0004', 'LYD');
    }

    #[Test]
    public function it_reads_wire_amounts_only_when_they_have_at_most_three_decimal_places(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Anis amounts have at most three decimal places');
        Money::fromArray(['amount' => '0.0004', 'currency' => 'LYD']);
    }

    #[Test]
    public function it_pads_a_short_amount_without_rounding(): void
    {
        self::assertSame('10.500', Money::of('10.5', 'LYD')->amount());
    }

    #[Test]
    public function it_preserves_negative_thousandths_for_the_order_price_guard(): void
    {
        self::assertSame('-0.001', Money::of('-0.001', 'LYD')->amount());
    }

    #[Test]
    public function it_refuses_a_weakly_coerced_fractional_string_as_quantity(): void
    {
        try {
            eval('declare(strict_types=0); return new \\Anis\\Partners\\Models\\CreateOrderRequest("8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48", "1.9", \\Anis\\Partners\\Models\\Money::of("10.5", "LYD"), \\Anis\\Partners\\Models\\Money::of("10.5", "LYD"));');
            self::fail('A weak caller must not coerce a fractional string into a purchase quantity.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('quantity must be an integer', $exception->getMessage());
        }
    }

    #[Test]
    public function it_refuses_weakly_coerced_text_as_debt_consent(): void
    {
        try {
            eval('declare(strict_types=0); return new \\Anis\\Partners\\Models\\CreateOrderRequest("8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48", 1, \\Anis\\Partners\\Models\\Money::of("10.5", "LYD"), \\Anis\\Partners\\Models\\Money::of("10.5", "LYD"), useAllowedDebt: "false");');
            self::fail('A weak caller must not coerce text into debt consent.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('consent must be a boolean', $exception->getMessage());
        }

    }
    #[Test]
    public function it_refuses_weakly_coerced_money_thousandths(): void
    {
        try {
            eval('declare(strict_types=0); return new \\Anis\\Partners\\Models\\Money("1000", "LYD");');
            self::fail('A weak caller must not coerce money thousandths from text.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('integer thousandths', $exception->getMessage());
        }
    }

    #[Test]
    public function it_refuses_a_weakly_coerced_money_multiplication_quantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        eval('declare(strict_types=0); return \\Anis\\Partners\\Models\\Money::of("10.5", "LYD")->multiply("1.9");');
    }

    #[Test]
    public function it_refuses_a_non_string_money_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        eval('declare(strict_types=0); return \\Anis\\Partners\\Models\\Money::of("1.000", 42);');
    }

    #[Test]
    public function it_writes_order_request_json_with_contract_member_names(): void
    {
        $request = new \Anis\Partners\Models\CreateOrderRequest(
            '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48',
            2,
            new Money(10500, 'LYD'),
            new Money(21000, 'LYD'),
            'sale/77',
        );

        self::assertSame(
            '{"cardId":"8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48","quantity":2,"expectedUnitPrice":{"amount":"10.500","currency":"LYD"},"expectedTotal":{"amount":"21.000","currency":"LYD"},"externalReference":"sale/77","useAllowedDebt":false}',
            $request->toJson(),
        );
    }
}
