<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes a purchasable card and the exact unit price this wallet must accept. */
final readonly class CatalogueCard implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $id,
        public string $subcategoryId,
        public ?LocalizedText $name = null,
        public ?string $faceValue = null,
        public ?Money $unitPrice = null,
        public ?Money $businessPrice = null,
        public ?Money $personalPrice = null,
        public bool $hasSpecialOffer = false,
        public ?Money $specialOfferPrice = null,
        public bool $available = false,
        public ?int $minimumQuantity = null,
        public ?int $maximumQuantity = null,
    ) {}

    /**
     * Reads all published prices without deriving one from another wallet-facing amount.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');
        $prices = [];
        foreach (['unitPrice', 'businessPrice', 'personalPrice', 'specialOfferPrice'] as $field) {
            $value = ModelData::object($data, $field);
            $prices[$field] = $value === null ? null : Money::fromArray($value);
        }

        return new self(
            ModelData::uuid($data, 'id'),
            ModelData::uuid($data, 'subcategoryId'),
            $name === null ? null : LocalizedText::fromArray($name),
            ModelData::nullableString($data, 'faceValue'),
            $prices['unitPrice'],
            $prices['businessPrice'],
            $prices['personalPrice'],
            ModelData::boolean($data, 'hasSpecialOffer'),
            $prices['specialOfferPrice'],
            ModelData::boolean($data, 'available'),
            ModelData::nullableInteger($data, 'minimumQuantity'),
            ModelData::nullableInteger($data, 'maximumQuantity'),
        );
    }
}
