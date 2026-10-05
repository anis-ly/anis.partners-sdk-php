<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Carries a revealed credential; handle the serial and voucher as secrets. */
final readonly class RevealedCredential implements \JsonSerializable
{
    use WireJsonSerialization;
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $soldCardId,
        public ?string $serialNumber = null,
        public ?string $voucher = null,
        public ?\DateTimeImmutable $revealedAt = null,
        public ?string $expiryDate = null,
        public ?string $invoiceId = null,
        public ?MaskedCardProduct $card = null,
        public ?\DateTimeImmutable $purchasedAt = null,
    ) {}

    /**
     * Reads a protected reveal answer while preserving every supplied sale detail.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $card = ModelData::object($data, 'card');

        return new self(
            ModelData::uuid($data, 'soldCardId'),
            ModelData::nullableString($data, 'serialNumber'),
            ModelData::nullableString($data, 'voucher'),
            ModelData::dateTime($data, 'revealedAt'),
            ModelData::date($data, 'expiryDate'),
            ModelData::nullableUuid($data, 'invoiceId'),
            $card === null ? null : MaskedCardProduct::fromArray($card),
            ModelData::dateTime($data, 'purchasedAt'),
        );
    }

    /** Redacts secret fields so accidental string interpolation cannot disclose a voucher or serial. */
    public function __toString(): string
    {
        return 'RevealedCredential { SoldCardId = ' . $this->soldCardId . ', Secret = <redacted> }';
    }

    /** Hides voucher and serial values from var_dump and other native object inspection. */
    public function __debugInfo(): array
    {
        return [
            'soldCardId' => $this->soldCardId,
            'credentials' => '<redacted>',
            'revealedAt' => $this->revealedAt,
            'expiryDate' => $this->expiryDate,
            'invoiceId' => $this->invoiceId,
            'card' => $this->card,
            'purchasedAt' => $this->purchasedAt,
        ];
    }
}
