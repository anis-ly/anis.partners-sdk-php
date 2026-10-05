<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Keeps owner supplied language strings separate because neither language has a guaranteed fallback. */
final readonly class LocalizedText implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(public ?string $ar = null, public ?string $en = null) {}

    /**
     * Reads the optional translations without inventing a missing language.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ModelData::nullableString($data, 'ar'), ModelData::nullableString($data, 'en'));
    }
}
