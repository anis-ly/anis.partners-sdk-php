<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the catalogue card represented on a sold card or revealed credential. */
final readonly class MaskedCardProduct implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(public string $id, public ?LocalizedText $name = null) {}

    /**
     * Reads the published card identity and optional display name.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = ModelData::object($data, 'name');

        return new self(ModelData::uuid($data, 'id'), $name === null ? null : LocalizedText::fromArray($name));
    }
}
