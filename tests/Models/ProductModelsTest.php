<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\DirectRechargeAccepted;
use Anis\Partners\Models\MeterCharge;
use Anis\Partners\Models\MeterChargeAccepted;
use Anis\Partners\Models\MeterPackage;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductModelsTest extends TestCase
{
    #[Test]
    public function it_requires_total_and_personal_price_on_a_full_meter_read_and_omits_completed_at_unless_succeeded(): void
    {
        foreach (['processing', 'failed'] as $status) {
            self::assertNull(MeterCharge::fromArray(P::meterCharge($status))->completedAt);
        }
        $data = P::meterCharge('processing');
        unset($data['personalPrice']);
        $this->expectException(\Anis\Partners\Errors\AnisPartnersUnexpectedValueException::class);
        MeterCharge::fromArray($data);
    }

    #[Test]
    public function it_does_not_invent_a_created_at_for_a_full_read(): void
    {
        $data = P::meterCharge('processing');
        unset($data['createdAt']);
        $this->expectException(\Anis\Partners\Errors\AnisPartnersUnexpectedValueException::class);
        MeterCharge::fromArray($data);
    }

    #[Test]
    public function it_keeps_leading_zeros_spaces_and_decimal_strings_exactly(): void
    {
        $charge = MeterCharge::fromArray(P::meterCharge('succeeded'));

        self::assertSame('0268000710262', $charge->meterNumber);
        self::assertSame('1234 5678 9012 3456 7890', $charge->receipt?->tokens[0]->code);
        self::assertSame('88.640', $charge->receipt->creditedAmount);
        self::assertSame('5.000', $charge->receipt->taxes[0]->amount);
        self::assertSame('ضريبة الاستهلاك', $charge->receipt->taxes[0]->description);
    }

    #[Test]
    public function it_round_trips_a_meter_charge_to_its_wire_members(): void
    {
        $wire = P::meterCharge('succeeded');
        $json = json_decode(json_encode(MeterCharge::fromArray($wire), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        self::assertSame($wire['receipt'], $json['receipt']);
        self::assertSame('succeeded', $json['status']);
        self::assertSame('2026-10-20T08:13:02Z', $json['completedAt']);
    }

    #[Test]
    public function it_omits_an_absent_business_price_instead_of_inventing_zero(): void
    {
        $package = MeterPackage::fromArray(['id' => P::PACKAGE, 'name' => 'p', 'gecolPackageName' => 'G', 'logo' => 'l', 'unitPrice' => ['amount' => '1.000', 'currency' => 'LYD'], 'personalPrice' => ['amount' => '1.000', 'currency' => 'LYD']]);

        self::assertNull($package->businessPrice);
    }

    #[Test]
    public function it_rebuilds_a_create_request_from_its_journal_form_with_identical_bytes(): void
    {
        $meter = new \Anis\Partners\Models\CreateMeterChargeRequest(P::PACKAGE, '0268000710262', \Anis\Partners\Models\Money::of('97.500', 'LYD'), 'POS-1');
        $recharge = new \Anis\Partners\Models\CreateDirectRechargeRequest(P::PRODUCT, '1500.000', \Anis\Partners\Models\Money::of('237.500', 'LYD'), [new \Anis\Partners\Models\DirectRechargeRecipientInput('recipient_number', '01012345678')], 'note');

        self::assertSame($meter->toJson(), \Anis\Partners\Models\CreateMeterChargeRequest::fromArray($meter->toArray())->toJson());
        self::assertSame($recharge->toJson(), \Anis\Partners\Models\CreateDirectRechargeRequest::fromArray($recharge->toArray())->toJson());
    }

    #[Test]
    public function it_serialises_the_accepted_answers_with_exactly_three_members(): void
    {
        self::assertSame(['operationId' => P::METER_OPERATION, 'operationType' => 'meterCharge', 'status' => 'processing'], MeterChargeAccepted::fromArray(['operationId' => P::METER_OPERATION, 'operationType' => 'meterCharge', 'status' => 'processing'])->jsonSerialize());
        self::assertSame('directRecharge', DirectRechargeAccepted::fromArray(['operationId' => P::RECHARGE_OPERATION, 'operationType' => 'directRecharge', 'status' => 'processing'])->jsonSerialize()['operationType']);
    }

    /** @return iterable<string, array{class-string, array<array-key, mixed>}> */
    public static function malformedProducts(): iterable
    {
        foreach (['operationId', 'walletId', 'status'] as $key) {
            foreach (['missing', 'null', 'wrong type'] as $variant) {
                $data = P::meterCharge();
                if ($variant === 'missing') {
                    unset($data[$key]);
                } else {
                    $data[$key] = $variant === 'null' ? null : 1;
                }
                yield "meter $key $variant" => [MeterCharge::class, $data];
            }
        }
        $data = P::meterCharge();
        self::assertIsArray($data['package']);
        unset($data['package']['id']);
        yield 'package id' => [MeterCharge::class, $data];
        foreach (['tokens', 'taxes'] as $key) {
            $data = P::meterCharge();
            self::assertIsArray($data['receipt']);
            unset($data['receipt'][$key]);
            yield $key => [MeterCharge::class, $data];
        }
        foreach (['id', 'available', 'recipientFields'] as $key) {
            $product = ['id' => P::PRODUCT, 'title' => 'x', 'type' => 'futureType', 'payoutCurrency' => ['name' => 'c'], 'available' => true, 'recipientFields' => []];
            unset($product[$key]);
            yield "product $key" => [\Anis\Partners\Models\DirectRechargeProduct::class, $product];
        }
        $data = P::directRecharge();
        unset($data['recipient']);
        yield 'recipient' => [\Anis\Partners\Models\DirectRecharge::class, $data];
        $summary = P::directRecharge() + ['hasPartialRefund' => false];
        unset($summary['hasPartialRefund']);
        yield 'refund flag' => [\Anis\Partners\Models\DirectRechargeSummary::class, $summary];
        foreach (['url', 'generation', 'status'] as $key) {
            $data = ['url' => null, 'generation' => null, 'status' => 'notConfigured'];
            unset($data[$key]);
            yield "endpoint $key" => [\Anis\Partners\Models\WebhookEndpoint::class, $data];
        }
        foreach (['lastAttemptAt', 'nextAttemptAt', 'lastResponseCode', 'lastFailureKind'] as $key) {
            $data = ['id' => P::METER_OPERATION, 'type' => 'future.type', 'status' => 'pending', 'attempts' => 0,
                'lastAttemptAt' => null, 'nextAttemptAt' => null, 'lastResponseCode' => null, 'lastFailureKind' => null];
            unset($data[$key]);
            yield "event $key" => [\Anis\Partners\Models\WebhookEvent::class, $data];
        }
        yield 'failure kind wrong type' => [\Anis\Partners\Models\WebhookEvent::class,
            ['id' => P::METER_OPERATION, 'type' => 'x', 'status' => 'pending', 'attempts' => 0,
                'lastAttemptAt' => null, 'nextAttemptAt' => null, 'lastResponseCode' => null, 'lastFailureKind' => 1]];
    }

    /**
     * @param class-string $model
     * @param array<array-key, mixed> $data
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedProducts')]
    public function it_rejects_missing_or_malformed_required_product_members(string $model, array $data): void
    {
        $this->expectException(\Anis\Partners\Errors\AnisPartnersUnexpectedValueException::class);
        $model::fromArray($data);
    }

    #[Test]
    public function it_keeps_unknown_enum_strings_and_optional_members_compatible(): void
    {
        $charge = MeterCharge::fromArray(P::meterCharge('aFutureState'));
        self::assertSame(\Anis\Partners\Models\MeterChargeStatus::Unknown, $charge->status);
        self::assertNull($charge->receipt);
        self::assertSame(
            ['url' => null, 'status' => 'notConfigured', 'generation' => null],
            \Anis\Partners\Models\WebhookEndpoint::fromArray(['url' => null, 'status' => 'notConfigured', 'generation' => null])->jsonSerialize(),
        );
    }

    #[Test]
    public function it_validates_payout_positivity_without_converting_decimal_strings_to_floats(): void
    {
        foreach (['0.001', '0000.001', '99999999999999999999999999999.001'] as $amount) {
            $request = new \Anis\Partners\Models\CreateDirectRechargeRequest(P::PRODUCT, $amount, \Anis\Partners\Models\Money::of('1.000', 'LYD'), []);
            self::assertSame($amount, $request->payoutAmount);
        }
        foreach (['0.000', '0000.000'] as $amount) {
            try {
                new \Anis\Partners\Models\CreateDirectRechargeRequest(P::PRODUCT, $amount, \Anis\Partners\Models\Money::of('1.000', 'LYD'), []);
                self::fail('A zero payout must be rejected.');
            } catch (\Anis\Partners\Errors\AnisPartnersInvalidArgumentException $error) {
                self::assertStringContainsString('positive', $error->getMessage());
            }
        }
    }
}
