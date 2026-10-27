<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Summarizes one notification delivery; all eight members are always present, with null where nothing happened yet. */
final readonly class WebhookEvent implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public WebhookEventType $type,
        public string $rawType,
        public WebhookDeliveryStatus $status,
        public int $attempts,
        public ?\DateTimeImmutable $lastAttemptAt,
        public ?\DateTimeImmutable $nextAttemptAt,
        public ?int $lastResponseCode,
        public ?WebhookFailureKind $lastFailureKind,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        ModelData::requireMembers($data, 'lastAttemptAt', 'nextAttemptAt', 'lastResponseCode', 'lastFailureKind');
        $kind = ModelData::nullableString($data, 'lastFailureKind');

        return new self(
            ModelData::requiredUuid($data, 'id'),
            WebhookEventType::parse(ModelData::requiredString($data, 'type')),
            ModelData::requiredString($data, 'type'),
            WebhookDeliveryStatus::parse(ModelData::requiredString($data, 'status')),
            ModelData::requiredInteger($data, 'attempts'),
            ModelData::dateTime($data, 'lastAttemptAt'),
            ModelData::dateTime($data, 'nextAttemptAt'),
            ModelData::nullableInteger($data, 'lastResponseCode'),
            $kind === null ? null : WebhookFailureKind::parse($kind),
        );
    }

    /**
     * Keeps explicit nulls and the wire spelling of the type.
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $utc = static fn(?\DateTimeImmutable $time): ?string => $time?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $this->id,
            'type' => $this->rawType,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'lastAttemptAt' => $utc($this->lastAttemptAt),
            'nextAttemptAt' => $utc($this->nextAttemptAt),
            'lastResponseCode' => $this->lastResponseCode,
            'lastFailureKind' => $this->lastFailureKind?->value,
        ];
    }
}
