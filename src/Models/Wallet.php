<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents a wallet this application may act on after current access checks. */
final readonly class Wallet implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $currency = null,
        public Money $balance = new Money(0, ''),
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
            ModelData::nullableString($data, 'currency'),
            $balance === null ? new Money(0, '') : Money::fromArray($balance),
        );
    }
}
