<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the catalogue card represented on a sold card or revealed credential. */
final readonly class CardSummary implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public string $id,
        public ?LocalizedText $name = null,
        public ?string $faceValue = null,
        public ?string $logo = null,
        public ?CardGroup $subcategory = null,
        public ?CardGroup $category = null,
    ) {}

    /**
     * Reads the published card identity and optional display name.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');

        $subcategory = ModelData::object($data, 'subcategory');
        $category = ModelData::object($data, 'category');
        return new self(
            ModelData::uuid($data, 'id'),
            $name === null ? null : LocalizedText::fromArray($name),
            ModelData::nullableString($data, 'faceValue'),
            ModelData::nullableString($data, 'logo'),
            $subcategory === null ? null : CardGroup::fromArray($subcategory),
            $category === null ? null : CardGroup::fromArray($category),
        );
    }
}
