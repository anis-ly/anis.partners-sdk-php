<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one direct recharge in a list; recipient details and proof come from the single read. */
final readonly class DirectRechargeSummary implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $operationId,
        public DirectRechargeStatus $status,
        public int $number,
        public DirectRechargeProductReference $product,
        public DirectRechargePayout $payout,
        public Money $total,
        public ?string $externalReference,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $completedAt,
        public bool $hasPartialRefund,
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
            DirectRechargeProductReference::fromArray(ModelData::requiredObject($data, 'product')),
            DirectRechargePayout::fromArray(ModelData::requiredObject($data, 'payout')),
            Money::fromArray(ModelData::requiredObject($data, 'total')),
            ModelData::nullableString($data, 'externalReference'),
            ModelData::requiredDateTime($data, 'requestedAt'),
            ModelData::dateTime($data, 'completedAt'),
            ModelData::requiredBoolean($data, 'hasPartialRefund'),
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
