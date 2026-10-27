<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one direct recharge product with the details its recipient must give. */
final readonly class DirectRechargeProduct implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    /**
     * @param list<DirectRechargeRecipientField> $recipientFields
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public DirectRechargeProductType $type,
        public ?DirectRechargeCategory $category,
        public DirectRechargeCurrency $payoutCurrency,
        public bool $available,
        public ?DirectRechargeLimits $limits,
        public array $recipientFields,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredUuid($data, 'id'),
            ModelData::requiredString($data, 'title'),
            ModelData::nullableString($data, 'description'),
            DirectRechargeProductType::parse(ModelData::requiredString($data, 'type')),
            self::optional($data, 'category', DirectRechargeCategory::fromArray(...)),
            DirectRechargeCurrency::fromArray(ModelData::requiredObject($data, 'payoutCurrency')),
            ModelData::requiredBoolean($data, 'available'),
            self::optional($data, 'limits', DirectRechargeLimits::fromArray(...)),
            self::each(ModelData::requiredObjectList($data, 'recipientFields'), DirectRechargeRecipientField::fromArray(...)),
        );
    }

    /**
     * @template T of object
     * @param array<array-key, mixed> $data
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return T|null
     */
    private static function optional(array $data, string $key, callable $hydrate): ?object
    {
        $value = ModelData::object($data, $key);

        return $value === null ? null : $hydrate($value);
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
