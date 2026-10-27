<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Is the exact three-member answer to a meter charge that is still processing. */
final readonly class MeterChargeAccepted implements \JsonSerializable
{
    public const OPERATION_TYPE = 'meterCharge';
    public const STATUS = 'processing';

    public function __construct(public string $operationId) {}

    /**
     * Accepts only operationId, operationType meterCharge and status processing; anything else is a contract mismatch.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['operationId', 'operationType', 'status']
            || ($data['operationType'] ?? null) !== self::OPERATION_TYPE
            || ($data['status'] ?? null) !== self::STATUS
            || !is_string($data['operationId'])) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('A processing answer must carry exactly operationId, operationType meterCharge and status processing.');
        }

        return new self(ModelData::requiredUuid($data, 'operationId'));
    }

    /** @return array{operationId: string, operationType: string, status: string} */
    public function jsonSerialize(): array
    {
        return ['operationId' => $this->operationId, 'operationType' => self::OPERATION_TYPE, 'status' => self::STATUS];
    }
}
