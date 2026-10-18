<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the category of a catalogue card. */
final readonly class CatalogueCardCategory implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public string $id,
        public ?LocalizedText $name = null,
        public CatalogueCategoryType $type = CatalogueCategoryType::Unknown,
    ) {}

    /**
     * Reads the category identity, optional display name, and category type.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');

        return new self(
            ModelData::uuid($data, 'id'),
            $name === null ? null : LocalizedText::fromArray($name),
            CatalogueCategoryType::parse($data['type'] ?? null),
        );
    }
}
