<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** @internal Converts nested model values to the wire-friendly values expected by JSON consumers. */
trait WireJsonSerialization
{
    /**
     * Serializes public response members with UTC timestamps and backed enum wire values.
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return self::serializeWireMembers(get_object_vars($this));
    }

    /** @param array<array-key, mixed> $members
     *  @return array<array-key, mixed>
     */
    private static function serializeWireMembers(array $members): array
    {
        foreach ($members as $key => $value) {
            $members[$key] = self::serializeWireValue($value);
        }

        return $members;
    }

    private static function serializeWireValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }
        if (is_array($value)) {
            return self::serializeWireMembers($value);
        }
        if (is_object($value)) {
            return self::serializeWireMembers(get_object_vars($value));
        }

        return $value;
    }
}
