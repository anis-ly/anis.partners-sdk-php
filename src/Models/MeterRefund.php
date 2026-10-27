<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says whether the money of a failed charge is back. */
final readonly class MeterRefund implements \JsonSerializable
{
    use WireJsonSerialization {
        jsonSerialize as private serializeMembers;
    }

    public function __construct(
        public MeterRefundStatus $status,
        public ?Money $amount,
    ) {}

    /**
     * Reads the wire members; an optional member that is absent stays null instead of receiving an invented value.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = MeterRefundStatus::parse(ModelData::requiredString($data, 'status'));
        if ($status === MeterRefundStatus::Pending || $status === MeterRefundStatus::Refunded) {
            ModelData::requiredObject($data, 'amount');
        }

        return new self(
            $status,
            self::optional($data, 'amount', Money::fromArray(...)),
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
