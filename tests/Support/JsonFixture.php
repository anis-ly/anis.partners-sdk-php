<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

final class JsonFixture
{
    /** @return array<string, mixed> */
    public static function readObject(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('The conformance fixture could not be read.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::object($decoded);
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('The conformance fixture expected a JSON object.');
        }
        $object = [];
        foreach ($value as $key => $member) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('The conformance fixture expected string object keys.');
            }
            $object[$key] = $member;
        }

        return $object;
    }

    /** @param array<string, mixed> $object */
    public static function string(array $object, string $key): string
    {
        $value = $object[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("The fixture member '{$key}' must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    public static function nullableString(array $object, string $key): ?string
    {
        $value = $object[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException("The fixture member '{$key}' must be a string or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    public static function integer(array $object, string $key): int
    {
        $value = $object[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("The fixture member '{$key}' must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $object */
    public static function boolean(array $object, string $key): bool
    {
        $value = $object[$key] ?? null;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException("The fixture member '{$key}' must be a boolean.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $object, string $key): array
    {
        $value = $object[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("The fixture member '{$key}' must be a list.");
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::object($item);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, string>
     */
    public static function stringMap(array $object, string $key): array
    {
        $values = self::object($object[$key] ?? null);
        foreach ($values as $name => $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException("The fixture header '{$name}' must be a string.");
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $object */
    public static function encode(array $object): string
    {
        return json_encode($object, JSON_THROW_ON_ERROR);
    }

    public static function base64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new \UnexpectedValueException('The fixture contains invalid base64.');
        }

        return $decoded;
    }
}
