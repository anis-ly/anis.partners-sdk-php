<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the catalogue subcategory represented on a sold card. */
final readonly class MaskedCardSubcategory implements \JsonSerializable
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
