<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes a direct recharge as the application-scoped owner read answers it. */
final readonly class DirectRecharge implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    /**
     * @param list<DirectRechargeRecipientValue> $recipient
     */
    public function __construct(
        public string $operationId,
        public DirectRechargeStatus $status,
        public int $number,
        public string $walletId,
        public DirectRechargeProductReference $product,
        public DirectRechargePayout $payout,
        public string $exchangeRate,
        public Money $price,
        public Money $commission,
        public Money $total,
        public array $recipient,
        public \DateTimeImmutable $requestedAt,
        public ?string $note,
        public ?string $externalReference,
        public ?\DateTimeImmutable $completedAt,
        public ?string $proofUrl,
        public ?string $reason,
        public ?DirectRechargePartialRefund $partialRefund,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredUuid($data, 'operationId'),
            DirectRechargeStatus::parse(ModelData::requiredString($data, 'status')),
            ModelData::requiredInteger($data, 'number'),
            ModelData::requiredUuid($data, 'walletId'),
            DirectRechargeProductReference::fromArray(ModelData::requiredObject($data, 'product')),
            DirectRechargePayout::fromArray(ModelData::requiredObject($data, 'payout')),
            ModelData::decimalString($data, 'exchangeRate'),
            Money::fromArray(ModelData::requiredObject($data, 'price')),
            Money::fromArray(ModelData::requiredObject($data, 'commission')),
            Money::fromArray(ModelData::requiredObject($data, 'total')),
            self::each(ModelData::requiredObjectList($data, 'recipient'), DirectRechargeRecipientValue::fromArray(...)),
            ModelData::requiredDateTime($data, 'requestedAt'),
            ModelData::nullableString($data, 'note'),
            ModelData::nullableString($data, 'externalReference'),
            ModelData::dateTime($data, 'completedAt'),
            ModelData::nullableString($data, 'proofUrl'),
            ModelData::nullableString($data, 'reason'),
            self::optional($data, 'partialRefund', DirectRechargePartialRefund::fromArray(...)),
        );
    }

    /**
     * @template T of object
     * @param array<array-key, mixed> $data
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return T|null
     */
    private static function optional(array $data, string $key, callable $hydrate): ?object
    {
        $value = ModelData::object($data, $key);

        return $value === null ? null : $hydrate($value);
    }

    /**
     * @template T of object
     * @param list<array<array-key, mixed>> $items
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return list<T>
     */
    private static function each(array $items, callable $hydrate): array
    {
        return array_map($hydrate, $items);
    }

    /**
     * Writes the members that are present; an absent optional member is left out rather than sent as null.
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter($this->serializeMembers(), static fn(mixed $member): bool => $member !== null);
    }
}
