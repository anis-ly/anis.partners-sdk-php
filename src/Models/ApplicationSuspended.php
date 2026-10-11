<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries the confirmed access stop state. */
final readonly class ApplicationSuspended implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(public string $applicationId, public string $status, public ?\DateTimeImmutable $suspendedAt = null) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = ModelData::nullableString($data, 'status');
        if ($status !== 'suspended') {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('The access stop status is invalid.');
        }
        return new self(ModelData::uuid($data, 'applicationId'), $status, ModelData::dateTime($data, 'suspendedAt'));
    }
}
