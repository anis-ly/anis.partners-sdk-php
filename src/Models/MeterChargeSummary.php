<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one meter charge in a list; the receipt and tokens come from the single read. */
final readonly class MeterChargeSummary implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $operationId,
        public MeterChargeStatus $status,
        public string $meterNumber,
        public MeterChargePackage $package,
        public Money $total,
        public ?string $externalReference,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $completedAt,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredUuid($data, 'operationId'),
            MeterChargeStatus::parse(ModelData::requiredString($data, 'status')),
            ModelData::requiredString($data, 'meterNumber'),
            MeterChargePackage::fromArray(ModelData::requiredObject($data, 'package')),
            Money::fromArray(ModelData::requiredObject($data, 'total')),
            ModelData::nullableString($data, 'externalReference'),
            ModelData::requiredDateTime($data, 'createdAt'),
            ModelData::dateTime($data, 'completedAt'),
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
