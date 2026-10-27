<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Holds the limits for this wallet; a limit the tier does not have stays absent. */
final readonly class DirectRechargeLimits implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public ?Money $minimumTotal,
        public ?Money $maximumTotal,
        public ?string $minimumPayout,
        public ?string $maximumPayout,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::optional($data, 'minimumTotal', Money::fromArray(...)),
            self::optional($data, 'maximumTotal', Money::fromArray(...)),
            ModelData::nullableDecimalString($data, 'minimumPayout'),
            ModelData::nullableDecimalString($data, 'maximumPayout'),
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
