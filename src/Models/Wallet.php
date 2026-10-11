<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents a wallet this application may act on after current access checks. */
final readonly class Wallet implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?Money $balance = null,
    ) {}

    /**
     * Reads the wallet projection without adding account or subscription internals.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $balance = ModelData::object($data, 'balance');

        return new self(
            ModelData::uuid($data, 'id'),
            ModelData::nullableString($data, 'name'),
            $balance === null ? null : Money::fromArray($balance),
        );
    }
}
