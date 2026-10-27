<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

/** Builds the wire answers of the meter and direct recharge routes for tests. */
final class ProductPayloads
{
    public const WALLET = '71825266-7182-4182-8182-718252667182';
    public const METER_OPERATION = '3c1e9b7a-0d42-4f5e-8a61-2b9c7d0e5f13';
    public const PACKAGE = '5f3b8c21-7a0d-4e96-b1c4-2d8e6f9a0b37';
    public const RECHARGE_OPERATION = '7d4e1a90-5b2c-4e7f-8a13-0c9d6e2b4f81';
    public const PRODUCT = 'e7c2a9d4-1b3f-4a85-9c60-8d2e4f7b1a93';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function meterCharge(string $status = 'succeeded', array $overrides = []): array
    {
        $charge = [
            'operationId' => self::METER_OPERATION,
            'status' => $status,
            'walletId' => self::WALLET,
            'meterNumber' => '0268000710262',
            'package' => ['id' => self::PACKAGE, 'name' => 'باقة 100 دينار', 'gecolPackageName' => 'PKG_100'],
            'total' => ['amount' => '97.500', 'currency' => 'LYD'],
            'personalPrice' => ['amount' => '100.000', 'currency' => 'LYD'],
            'externalReference' => 'POS-77-INV-1093',
            'createdAt' => '2026-10-20T08:12:44Z',
        ];
        if ($status === 'succeeded') {
            $charge['completedAt'] = '2026-10-20T08:13:02Z';
            $charge['receipt'] = [
                'receiptNumber' => '260020103744',
                'creditedAmount' => '88.640',
                'tokens' => [
                    ['sequence' => 1, 'label' => 'KCT1', 'code' => '1234 5678 9012 3456 7890', 'description' => 'Key change token 1'],
                    ['sequence' => 2, 'label' => 'KCT2', 'code' => '2345 6789 0123 4567 8901'],
                    ['sequence' => 3, 'label' => 'STS', 'code' => '5821 0034 7719 2265 0418', 'description' => 'Credit token'],
                ],
                'taxes' => [['description' => 'ضريبة الاستهلاك', 'amount' => '5.000'], ['description' => 'رسوم الخدمة', 'amount' => '3.860']],
            ];
        }
        if ($status === 'failed') {
            $charge['failure'] = ['code' => 'meter_debt_exceeds_package', 'message' => ['ar' => 'مديونية العداد أكبر من قيمة الباقة', 'en' => 'The meter debt is larger than the package']];
            $charge['refund'] = ['status' => 'refunded', 'amount' => ['amount' => '97.500', 'currency' => 'LYD']];
        }

        return $overrides + $charge;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function directRecharge(string $status = 'completed', array $overrides = []): array
    {
        $recharge = [
            'operationId' => self::RECHARGE_OPERATION,
            'status' => $status,
            'number' => 30412,
            'walletId' => self::WALLET,
            'product' => ['id' => self::PRODUCT, 'title' => 'فودافون كاش مصر'],
            'payout' => ['amount' => '1500.000', 'currency' => ['name' => 'جنيه مصري']],
            'exchangeRate' => '0.155000',
            'price' => ['amount' => '232.500', 'currency' => 'LYD'],
            'commission' => ['amount' => '5.000', 'currency' => 'LYD'],
            'total' => ['amount' => '237.500', 'currency' => 'LYD'],
            'recipient' => [
                ['key' => 'recipient_number', 'name' => ['ar' => 'رقم المحفظة', 'en' => 'Wallet number'], 'value' => '01012345678'],
                ['key' => 'recipient_location', 'name' => ['ar' => 'المدينة', 'en' => 'City'], 'value' => '1a2b3c4d-1111-4222-8333-444455556666', 'valueName' => 'القاهرة'],
            ],
            'requestedAt' => '2026-10-20T09:00:00Z',
        ];
        if ($status === 'completed') {
            $recharge['completedAt'] = '2026-10-20T11:42:10Z';
            $recharge['proofUrl'] = 'https://example.test/proof';
        }
        if ($status === 'suspendedDataError') {
            $recharge['reason'] = 'رقم المحفظة غير صحيح';
        }

        return $overrides + $recharge;
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function accepted(string $type, string $operationId): string
    {
        return self::json(['operationId' => $operationId, 'operationType' => $type, 'status' => 'processing']);
    }
}
