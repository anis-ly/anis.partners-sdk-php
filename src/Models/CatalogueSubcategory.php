<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes a catalogue subcategory, including buyer information when the owner supplies it. */
final readonly class CatalogueSubcategory implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $id,
        public string $categoryId,
        public ?LocalizedText $name = null,
        public ?LocalizedText $description = null,
        public ?string $logo = null,
        public bool $isBestSelling = false,
        public int $displayOrder = 0,
        public bool $available = false,
        public ?LocalizedText $disclaimer = null,
    ) {}

    /**
     * Reads optional translations and disclaimers without failing on older answers that omit them.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');
        $description = ModelData::object($data, 'description');
        $disclaimer = ModelData::object($data, 'disclaimer');

        return new self(
            ModelData::uuid($data, 'id'),
            ModelData::uuid($data, 'categoryId'),
            $name === null ? null : LocalizedText::fromArray($name),
            $description === null ? null : LocalizedText::fromArray($description),
            ModelData::nullableString($data, 'logo'),
            ModelData::boolean($data, 'isBestSelling'),
            ModelData::integer($data, 'displayOrder'),
            ModelData::boolean($data, 'available'),
            $disclaimer === null ? null : LocalizedText::fromArray($disclaimer),
        );
    }
}
