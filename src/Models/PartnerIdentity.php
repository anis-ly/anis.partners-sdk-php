<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the Partner that owns the current application. */
final readonly class PartnerIdentity implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(public string $id) {}

    /**
     * Reads the Partner identifier in canonical UUID form.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ModelData::uuid($data, 'id'));
    }
}
