<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries the confirmed access stop state. */
final readonly class KeyRevoked implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(public string $keyId, public string $status, public ?\DateTimeImmutable $revokedAt = null) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = ModelData::nullableString($data, 'status');
        if ($status !== 'revoked') {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('The access stop status is invalid.');
        }
        return new self(ModelData::uuid($data, 'keyId'), $status, ModelData::dateTime($data, 'revokedAt'));
    }
}
