<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents a sold card without disclosing its serial or voucher before reveal. */
final readonly class MaskedCard implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public string $id,
        public ?string $orderOperationId = null,
        public ?string $invoiceId = null,
        public ?MaskedCardProduct $card = null,
        public ?string $serialNumberMasked = null,
        public bool $credentialAvailable = false,
        public ?\DateTimeImmutable $purchasedAt = null,
        public ?Money $unitPrice = null,
        public ?string $expiryDate = null,
        public ?int $invoiceNumber = null,
        public ?string $faceValue = null,
        public ?MaskedCardSubcategory $subcategory = null,
    ) {}

    /**
     * Reads optional sale details while keeping plaintext credentials out of masked results.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $card = ModelData::object($data, 'card');
        $subcategory = ModelData::object($data, 'subcategory');
        $price = ModelData::object($data, 'unitPrice');

        return new self(
            ModelData::uuid($data, 'id'),
            ModelData::nullableUuid($data, 'orderOperationId'),
            ModelData::nullableUuid($data, 'invoiceId'),
            $card === null ? null : MaskedCardProduct::fromArray($card),
            ModelData::nullableString($data, 'serialNumberMasked'),
            ModelData::boolean($data, 'credentialAvailable'),
            ModelData::dateTime($data, 'purchasedAt'),
            $price === null ? null : Money::fromArray($price),
            ModelData::date($data, 'expiryDate'),
            ModelData::nullableInteger($data, 'invoiceNumber'),
            ModelData::nullableString($data, 'faceValue'),
            $subcategory === null ? null : MaskedCardSubcategory::fromArray($subcategory),
        );
    }
}
