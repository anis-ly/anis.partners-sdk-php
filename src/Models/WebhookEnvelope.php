<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/**
 * Holds a webhook delivery that passed verification.
 *
 * The data is the operation's current read at delivery time, so read `data` status as the truth: the event type only
 * says what triggered the delivery. A type this SDK does not know is kept with its raw spelling and raw data.
 */
final readonly class WebhookEnvelope
{
    /**
     * @param MeterCharge|DirectRecharge|null $data The typed read for the eight operational types, otherwise null.
     * @param array<array-key, mixed> $rawData The decoded data object.
     * @param string $dataRaw The data value exactly as received, for storage or hashing.
     */
    public function __construct(
        public string $id,
        public WebhookEventType $type,
        public string $rawType,
        public \DateTimeImmutable $occurredAt,
        public string $applicationId,
        public MeterCharge|DirectRecharge|null $data,
        #[\SensitiveParameter]
        public array $rawData,
        #[\SensitiveParameter]
        public string $dataRaw,
    ) {}

    /** Keeps meter tokens and recipient details out of dumps and logs. */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'type' => $this->rawType, 'occurredAt' => $this->occurredAt, 'applicationId' => $this->applicationId, 'data' => '<redacted>'];
    }
}
