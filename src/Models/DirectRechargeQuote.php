<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Shows what a direct recharge would pay out and cost; nothing is reserved. */
final readonly class DirectRechargeQuote implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $productId,
        public DirectRechargePayout $payout,
        public string $exchangeRate,
        public Money $price,
        public Money $commission,
        public Money $total,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredUuid($data, 'productId'),
            DirectRechargePayout::fromArray(ModelData::requiredObject($data, 'payout')),
            ModelData::decimalString($data, 'exchangeRate'),
            Money::fromArray(ModelData::requiredObject($data, 'price')),
            Money::fromArray(ModelData::requiredObject($data, 'commission')),
            Money::fromArray(ModelData::requiredObject($data, 'total')),
        );
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
