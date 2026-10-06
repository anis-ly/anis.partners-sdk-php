<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes the invitation and application state reported by the enrollment surface. */
final readonly class EnrollmentState implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public ?string $invitationId = null,
        public ?string $applicationId = null,
        public ?string $state = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * Reads invitation state while preserving its optional identifiers and expiry.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::nullableUuid($data, 'invitationId'),
            ModelData::nullableUuid($data, 'applicationId'),
            ModelData::nullableString($data, 'state'),
            ModelData::dateTime($data, 'expiresAt'),
        );
    }
}
