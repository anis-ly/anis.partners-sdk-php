<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Holds what the meter was credited with; amounts stay decimal strings exactly as the electricity company states them. */
final readonly class MeterReceipt implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    /**
     * @param list<MeterToken> $tokens
     * @param list<MeterTax> $taxes
     */
    public function __construct(
        public string $receiptNumber,
        public string $creditedAmount,
        public array $tokens,
        public array $taxes,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredString($data, 'receiptNumber'),
            ModelData::decimalString($data, 'creditedAmount'),
            self::each(ModelData::requiredObjectList($data, 'tokens'), MeterToken::fromArray(...)),
            self::each(ModelData::requiredObjectList($data, 'taxes'), MeterTax::fromArray(...)),
        );
    }

    /**
     * @template T of object
     * @param list<array<array-key, mixed>> $items
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return list<T>
     */
    private static function each(array $items, callable $hydrate): array
    {
        return array_map($hydrate, $items);
    }

    /**
     * Writes the members that are present; an absent optional member is left out rather than sent as null.
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter($this->serializeMembers(), static fn(mixed $member): bool => $member !== null);
    }
}
