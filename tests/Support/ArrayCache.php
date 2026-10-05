<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /** @param iterable<mixed, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $this->set($key, $value, $ttl);
            }
        }

        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
