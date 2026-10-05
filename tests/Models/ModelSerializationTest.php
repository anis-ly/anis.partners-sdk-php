<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Models\EnrollmentProofRequest;
use Anis\Partners\Models\EnrollmentState;
use Anis\Partners\Models\EnrollmentStatus;
use Anis\Partners\Models\MaskedCard;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\Order;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\SignatureDiagnostic;
use Anis\Partners\Verification\PartnerJwk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelSerializationTest extends TestCase
{
    #[Test]
    public function it_serializes_money_as_its_decimal_wire_members(): void
    {
        $money = Money::fromArray(['amount' => '10.641', 'currency' => 'LYD', 'asOf' => '2026-10-05T12:34:56+02:00']);

        self::assertSame(
            ['amount' => '10.641', 'currency' => 'LYD', 'asOf' => '2026-10-05T10:34:56Z'],
            json_decode(json_encode($money, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function it_serializes_date_bearing_response_models_as_utc_wire_strings(): void
    {
        $date = new \DateTimeImmutable('2026-10-05T12:34:56+02:00');
        $models = [
            'expiresAt' => new EnrollmentState(expiresAt: $date),
            'keyExpiresAt' => new EnrollmentStatus(keyExpiresAt: $date),
            'purchasedAt' => new MaskedCard('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', purchasedAt: $date),
            'revealedAt' => new RevealedCredential('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', revealedAt: $date),
            'receivedAt' => new SignatureDiagnostic(receivedAt: $date),
            'completedAt' => new Order('9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34', completedAt: $date),
        ];

        foreach ($models as $field => $model) {
            $data = json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame('2026-10-05T10:34:56Z', $data[$field]);
        }
    }

    #[Test]
    public function it_serializes_nested_money_and_status_values_as_wire_members(): void
    {
        $order = Order::fromArray([
            'operationId' => '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
            'status' => 'completed',
            'total' => ['amount' => '10.641', 'currency' => 'LYD'],
        ]);
        $data = json_decode(json_encode($order, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        self::assertSame('completed', $data['status']);
        self::assertSame(['amount' => '10.641', 'currency' => 'LYD'], $data['total']);
    }

    #[Test]
    public function it_serializes_request_models_using_their_wire_array(): void
    {
        $price = Money::of('10.641', 'LYD');
        $order = new CreateOrderRequest(
            '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48',
            2,
            $price,
            $price->multiply(2),
        );
        $jwk = new PartnerJwk('EC', 'P-256', 'x-coordinate', 'y-coordinate', d: 'private-member');
        $enrollmentKey = new EnrollmentKeyRequest(
            $jwk,
            new \DateTimeImmutable('2026-10-05T12:34:56+02:00'),
            new \DateTimeImmutable('2027-10-05T12:34:56+02:00'),
        );
        $proof = new EnrollmentProofRequest('3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43', 4, 'proof');

        foreach ([$order, $enrollmentKey, $proof] as $request) {
            $data = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame($request->toArray(), $data);
            if (array_key_exists('publicJwk', $data)) {
                self::assertIsArray($data['publicJwk']);
                self::assertArrayNotHasKey('d', $data['publicJwk']);
            }
        }
    }
}
