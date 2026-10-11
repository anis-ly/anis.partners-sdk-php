<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the catalogue category or subcategory represented on a card. */
final readonly class CardGroup implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(public string $id, public ?LocalizedText $name = null) {}

    /**
     * Reads the published subcategory identity and optional display name.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');

        return new self(ModelData::uuid($data, 'id'), $name === null ? null : LocalizedText::fromArray($name));
    }
}
