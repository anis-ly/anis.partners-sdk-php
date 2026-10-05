<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

use Anis\Partners\Internal\Uuid;

/** Shared strict conversions keep optional wire members absent without inventing values. */
final class ModelData
{
    public const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /** @param array<array-key, mixed> $data */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a string or null.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function integer(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be an integer.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function nullableInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be an integer or null.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function boolean(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a boolean.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function uuid(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return self::NIL_UUID;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a UUID string.");
        }

        try {
            return Uuid::canonical($value);
        } catch (\InvalidArgumentException $error) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a UUID string.", 0, $error);
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function nullableUuid(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a UUID string.");
        }

        try {
            return Uuid::canonical($value);
        } catch (\InvalidArgumentException $error) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a UUID string.", 0, $error);
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function dateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $value = self::nullableString($data, $key);
        if ($value === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $error) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a date-time string.", 0, $error);
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function date(array $data, string $key): ?string
    {
        $value = self::nullableString($data, $key);
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a date in YYYY-MM-DD form.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be an object or null.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a list.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException("The '{$key}' model entries must be objects.");
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("The '{$key}' model member must be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException("The '{$key}' model entries must be strings.");
            }
        }

        return $value;
    }

    /**
     * Encodes model request data with the byte choices shared by the .NET SDK.
     * @param array<array-key, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
