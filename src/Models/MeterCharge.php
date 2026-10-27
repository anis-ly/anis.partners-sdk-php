<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes a meter charge as the application-scoped owner read answers it. */
final readonly class MeterCharge implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $operationId,
        public MeterChargeStatus $status,
        public string $walletId,
        public string $meterNumber,
        public MeterChargePackage $package,
        public Money $total,
        public Money $personalPrice,
        public \DateTimeImmutable $createdAt,
        public ?string $externalReference,
        public ?\DateTimeImmutable $completedAt,
        public ?MeterReceipt $receipt,
        public ?MeterFailure $failure,
        public ?MeterRefund $refund,
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
            ModelData::requiredUuid($data, 'walletId'),
            ModelData::requiredString($data, 'meterNumber'),
            MeterChargePackage::fromArray(ModelData::requiredObject($data, 'package')),
            Money::fromArray(ModelData::requiredObject($data, 'total')),
            Money::fromArray(ModelData::requiredObject($data, 'personalPrice')),
            ModelData::requiredDateTime($data, 'createdAt'),
            ModelData::nullableString($data, 'externalReference'),
            ModelData::dateTime($data, 'completedAt'),
            self::optional($data, 'receipt', MeterReceipt::fromArray(...)),
            self::optional($data, 'failure', MeterFailure::fromArray(...)),
            self::optional($data, 'refund', MeterRefund::fromArray(...)),
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
     * Writes the members that are present; an absent optional member is left out rather than sent as null.
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter($this->serializeMembers(), static fn(mixed $member): bool => $member !== null);
    }
}
