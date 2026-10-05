<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

/** @internal Holds the verified status, headers, and decoded payload returned by transport. */
final readonly class TransportResponse
{
    /**
     * @param array<string, string> $headers
     * @param array<array-key, mixed> $json
     * @param array<string, list<string>> $rawHeaders
     */
    public function __construct(public int $status, public array $headers, public array $json, public string $body, public array $rawHeaders = []) {}

    /** @internal Returns a response header without depending on its casing. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @internal
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        foreach ($this->rawHeaders as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values;
            }
        }

        return [];
    }
}
