<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Reports the enrollment proof, approval, and credential expiry state. */
final readonly class EnrollmentStatus implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public ?string $keyId = null,
        public ?int $challengeGeneration = null,
        public ?string $proofState = null,
        public ?string $approvalState = null,
        public ?string $state = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?\DateTimeImmutable $keyExpiresAt = null,
    ) {}

    /**
     * Reads status details while tolerating members omitted at earlier enrollment steps.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::nullableUuid($data, 'keyId'),
            ModelData::nullableInteger($data, 'challengeGeneration'),
            ModelData::nullableString($data, 'proofState'),
            ModelData::nullableString($data, 'approvalState'),
            ModelData::nullableString($data, 'state'),
            ModelData::dateTime($data, 'expiresAt'),
            ModelData::dateTime($data, 'keyExpiresAt'),
        );
    }
}
