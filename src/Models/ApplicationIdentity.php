<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Identifies the application and the permissions effective for its current requests. */
final readonly class ApplicationIdentity implements \JsonSerializable
{
    use WireJsonSerialization;
    /**
     * Keeps the effective access list stable so callers can rely on the decoded grant snapshot.
     * @param list<string> $scopes
     */
    public function __construct(public string $id, public array $scopes = []) {}

    /**
     * Reads the effective scope list so integrations can display the current access granted to the app.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ModelData::uuid($data, 'id'), ModelData::stringList($data, 'scopes'));
    }
}
