<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Holds all credentials returned by one invoice reveal as a single result. */
final readonly class RevealedCredentialCollection implements \JsonSerializable
{
    use WireJsonSerialization;
    /**
     * Keeps one invoice reveal's credentials together so they cannot be confused with another sale.
     * @param list<RevealedCredential> $items
     */
    public function __construct(public array $items = []) {}

    /**
     * Reads the invoice result as credential models instead of untyped nested arrays.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn(array $item): RevealedCredential => RevealedCredential::fromArray($item),
            ModelData::objectList($data, 'items'),
        ));
    }

    /** Redacts credentials nested in the invoice result from native object inspection. */
    public function __debugInfo(): array
    {
        return ['items' => $this->items === [] ? [] : '<redacted>'];
    }
}
