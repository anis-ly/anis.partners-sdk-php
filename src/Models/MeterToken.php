<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Holds one token of a meter receipt; the electricity company's order and spacing are kept as sent. */
final readonly class MeterToken implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public int $sequence,
        public string $label,
        public string $code,
        public ?string $description,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredInteger($data, 'sequence'),
            ModelData::requiredString($data, 'label'),
            ModelData::requiredString($data, 'code'),
            ModelData::nullableString($data, 'description'),
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
