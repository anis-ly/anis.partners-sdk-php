<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

final class ThrowingCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new \RuntimeException('cache unavailable');
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw new \RuntimeException('cache unavailable');
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function clear(): bool
    {
        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new \RuntimeException('cache unavailable');
    }

    /** @param iterable<mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        throw new \RuntimeException('cache unavailable');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return false;
    }

    public function has(string $key): bool
    {
        throw new \RuntimeException('cache unavailable');
    }
}
