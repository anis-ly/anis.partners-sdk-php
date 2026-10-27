<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one detail the recipient or sender must give. */
final readonly class DirectRechargeRecipientField implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public string $key,
        public LocalizedText $name,
        public DirectRechargeFieldKind $kind,
        public DirectRechargeFieldFor $for,
        public int $order,
        public ?string $validation,
        public ?LocalizedText $validationMessage,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredString($data, 'key'),
            LocalizedText::fromArray(ModelData::requiredObject($data, 'name')),
            DirectRechargeFieldKind::parse(ModelData::requiredString($data, 'kind')),
            DirectRechargeFieldFor::parse(ModelData::requiredString($data, 'for')),
            ModelData::requiredInteger($data, 'order'),
            ModelData::nullableString($data, 'validation'),
            self::optional($data, 'validationMessage', LocalizedText::fromArray(...)),
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
     * Writes the members that are present; an absent optional member is left out rather than sent as null.
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter($this->serializeMembers(), static fn(mixed $member): bool => $member !== null);
    }
}
