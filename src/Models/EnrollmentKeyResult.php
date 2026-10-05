<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries the challenge and locally verifiable fingerprint returned for a submitted key. */
final readonly class EnrollmentKeyResult implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $keyId,
        public ?string $thumbprint = null,
        public ?string $safetyCode = null,
        public ?string $challenge = null,
        public ?int $challengeGeneration = null,
    ) {}

    /**
     * Reads exactly the published key-submission response members and canonicalizes its key ID.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::uuid($data, 'keyId'),
            ModelData::nullableString($data, 'thumbprint'),
            ModelData::nullableString($data, 'safetyCode'),
            ModelData::nullableString($data, 'challenge'),
            ModelData::nullableInteger($data, 'challengeGeneration'),
        );
    }
}
