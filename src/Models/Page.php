<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries one cursor page so callers can continue until the owner list is exhausted. */
/** @template T */
final readonly class Page implements \JsonSerializable
{
    use WireJsonSerialization;
    /**
     * Keeps one page and its continuation cursor together while the caller walks a list.
     * @param list<T> $items
     */
    public function __construct(public array $items = [], public ?string $nextCursor = null) {}

    /**
     * Reads a page envelope; operations turn each item into its route-specific model.
     * @param array<array-key, mixed> $data
     * @return Page<mixed>
     */
    public static function fromArray(array $data): self
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || !array_is_list($items)) {
            throw new \UnexpectedValueException('The page items member must be a list.');
        }

        return new self($items, ModelData::nullableString($data, 'nextCursor'));
    }
}
