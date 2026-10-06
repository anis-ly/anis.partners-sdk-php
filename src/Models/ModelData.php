<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

use Anis\Partners\Internal\Uuid;

/** Shared strict conversions keep optional wire members absent without inventing values. */
final class ModelData
{
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /** @param array<array-key, mixed> $data */
    public static function nullableString(#[\SensitiveParameter] array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be a string or null.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function integer(#[\SensitiveParameter] array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;
        if (!is_int($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be an integer.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function nullableInteger(#[\SensitiveParameter] array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be an integer or null.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function boolean(#[\SensitiveParameter] array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;
        if (!is_bool($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be a boolean.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function uuid(#[\SensitiveParameter] array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return self::NIL_UUID;
        }
        if (!is_string($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException(self::uuidMessage($key));
        }

        try {
            return Uuid::canonical($value);
        } catch (\Anis\Partners\Errors\AnisPartnersInvalidArgumentException $error) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException(self::uuidMessage($key), 0, $error);
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function nullableUuid(#[\SensitiveParameter] array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException(self::uuidMessage($key));
        }

        try {
            return Uuid::canonical($value);
        } catch (\Anis\Partners\Errors\AnisPartnersInvalidArgumentException $error) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException(self::uuidMessage($key), 0, $error);
        }
    }


    private static function uuidMessage(string $key): string
    {
        $name = preg_replace('/Id$/', ' id', $key) ?? $key;
        $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name));

        return 'The ' . $name . ' must be a UUID.';
    }

    /** @param array<array-key, mixed> $data */
    public static function dateTime(#[\SensitiveParameter] array $data, string $key): ?\DateTimeImmutable
    {
        $value = self::nullableString($data, $key);
        if ($value === null) {
            return null;
        }

        if (preg_match('/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-](?:0\d|1\d|2[0-3]):[0-5]\d)\z/D', $value, $parts) !== 1) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be an RFC 3339 date-time string with an offset.");
        }
        $fractionText = $parts[3];
        $fraction = str_pad(substr($fractionText, 0, 6), 6, '0');
        $withFraction = $fractionText !== '';
        $normalized = $parts[1] . 'T' . $parts[2] . ($withFraction ? '.' . $fraction : '') . $parts[4];
        $format = $withFraction ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        if ($parts[4] === 'Z') {
            $normalized = substr($normalized, 0, -1) . '+00:00';
        }
        $date = \DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be a valid RFC 3339 date-time string.");
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @param array<array-key, mixed> $data */
    public static function date(#[\SensitiveParameter] array $data, string $key): ?string
    {
        $value = self::nullableString($data, $key);
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be a date in YYYY-MM-DD form.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|null
     */
    public static function object(#[\SensitiveParameter] array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be an object or null.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function objectList(#[\SensitiveParameter] array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be a list.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model entries must be objects.");
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function stringList(#[\SensitiveParameter] array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model member must be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException("The '{$key}' model entries must be strings.");
            }
        }

        return $value;
    }

    /**
     * Encodes model request data with the byte choices required by the wire contract.
     * @param array<array-key, mixed> $data
     */
    public static function json(#[\SensitiveParameter] array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
