<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one meter package this wallet can buy, with the price this wallet pays and the retail price. */
final readonly class MeterPackage implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $id,
        public string $name,
        public string $gecolPackageName,
        public ?string $logo,
        public Money $unitPrice,
        public Money $personalPrice,
        public ?Money $businessPrice,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredUuid($data, 'id'),
            ModelData::requiredString($data, 'name'),
            ModelData::requiredString($data, 'gecolPackageName'),
            ModelData::nullableString($data, 'logo'),
            Money::fromArray(ModelData::requiredObject($data, 'unitPrice')),
            Money::fromArray(ModelData::requiredObject($data, 'personalPrice')),
            self::optional($data, 'businessPrice', Money::fromArray(...)),
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
