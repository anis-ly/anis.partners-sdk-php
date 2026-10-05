<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes a published catalogue category without requiring optional display content. */
final readonly class CatalogueCategory implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $id,
        public ?LocalizedText $name = null,
        public ?LocalizedText $description = null,
        public ?string $logo = null,
        public CatalogueCategoryType $type = CatalogueCategoryType::Unknown,
        public bool $inStock = false,
        public int $displayOrder = 0,
    ) {}

    /**
     * Reads the category and preserves future category types as Unknown.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');
        $description = ModelData::object($data, 'description');

        return new self(
            ModelData::uuid($data, 'id'),
            $name === null ? null : LocalizedText::fromArray($name),
            $description === null ? null : LocalizedText::fromArray($description),
            ModelData::nullableString($data, 'logo'),
            CatalogueCategoryType::parse(ModelData::nullableString($data, 'type')),
            ModelData::boolean($data, 'inStock'),
            ModelData::integer($data, 'displayOrder'),
        );
    }
}
