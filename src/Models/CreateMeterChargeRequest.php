<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one meter charge; the operation id travels only in the Idempotency-Key header. */
final readonly class CreateMeterChargeRequest implements \JsonSerializable
{
    /** Keeps the largest body Anis accepts for a money-operation create. */
    public const MAX_BODY_BYTES = 8192;

    public string $packageId;

    /**
     * @param string $meterNumber Kept as text so leading zeros survive.
     * @param Money $expectedTotal The total the partner expects to pay for the package.
     */
    public function __construct(
        string $packageId,
        public string $meterNumber,
        public Money $expectedTotal,
        public ?string $externalReference = null,
    ) {
        $this->packageId = \Anis\Partners\Internal\Uuid::canonical($packageId, 'package id');
        if ($meterNumber === '' || trim($meterNumber) !== $meterNumber) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A meter number must be given exactly as printed, without surrounding spaces.');
        }
        if ($expectedTotal->thousandths <= 0) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('ExpectedTotal must be greater than zero.');
        }
    }

    /**
     * Rebuilds a request from its stored wire form, for example from the journal written before the create.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::requiredUuid($data, 'packageId'),
            ModelData::requiredString($data, 'meterNumber'),
            Money::fromArray(ModelData::requiredObject($data, 'expectedTotal')),
            ModelData::nullableString($data, 'externalReference'),
        );
    }

    /**
     * Writes only the contract members; an absent reference is omitted.
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'packageId' => $this->packageId,
            'meterNumber' => $this->meterNumber,
            'expectedTotal' => $this->expectedTotal->toArray(),
        ];
        if ($this->externalReference !== null) {
            $data['externalReference'] = $this->externalReference;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Freezes the request JSON once; the same bytes are signed and sent. */
    public function toJson(): string
    {
        $json = ModelData::json($this->toArray());
        if (strlen($json) > self::MAX_BODY_BYTES) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A meter charge body may not exceed 8192 bytes.');
        }

        return $json;
    }
}
