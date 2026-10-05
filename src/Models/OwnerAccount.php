<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the owner account behind the application's wallets. */
final readonly class OwnerAccount implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(public string $id, public ?string $displayName = null) {}

    /**
     * Reads the owner account projection without exposing unrelated subscription data.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ModelData::uuid($data, 'id'), ModelData::nullableString($data, 'displayName'));
    }
}
