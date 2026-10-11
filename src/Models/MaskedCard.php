<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents a sold card without disclosing its serial or voucher before reveal. */
final readonly class MaskedCard implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public string $soldCardId,
        public ?string $operationId = null,
        public ?string $invoiceId = null,
        public ?int $invoiceNumber = null,
        public ?CardSummary $card = null,
        public ?string $serialNumberMasked = null,
        public bool $credentialAvailable = false,
        public ?\DateTimeImmutable $purchasedAt = null,
        public ?Money $unitPrice = null,
        public ?string $expiryDate = null,
    ) {}

    /**
     * Reads optional sale details while keeping plaintext credentials out of masked results.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $card = ModelData::object($data, 'card');
        $price = ModelData::object($data, 'unitPrice');

        return new self(
            ModelData::uuid($data, 'soldCardId'),
            ModelData::nullableUuid($data, 'operationId'),
            ModelData::nullableUuid($data, 'invoiceId'),
            ModelData::nullableInteger($data, 'invoiceNumber'),
            $card === null ? null : CardSummary::fromArray($card),
            ModelData::nullableString($data, 'serialNumberMasked'),
            ModelData::boolean($data, 'credentialAvailable'),
            ModelData::dateTime($data, 'purchasedAt'),
            $price === null ? null : Money::fromArray($price),
            ModelData::date($data, 'expiryDate'),
        );
    }
}
