<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Names the package of a meter charge. */
final readonly class MeterChargePackage implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $id,
        public string $name,
        public string $gecolPackageName,
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
