<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Holds a partial refund; it occurs only if partial refunds are enabled for the product. */
final readonly class DirectRechargePartialRefund implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $refundedPayout,
        public Money $refundedTotal,
        public string $remainingPayout,
        public Money $remainingTotal,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::decimalString($data, 'refundedPayout'),
            Money::fromArray(ModelData::requiredObject($data, 'refundedTotal')),
            ModelData::decimalString($data, 'remainingPayout'),
            Money::fromArray(ModelData::requiredObject($data, 'remainingTotal')),
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
