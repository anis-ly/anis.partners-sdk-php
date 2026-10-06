<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/**
 * Carries a revealed credential while keeping secret values out of PHP object dumps.
 */
final class RevealedCredential implements \JsonSerializable
{
    use WireJsonSerialization;

    public readonly string $soldCardId;
    public readonly ?\DateTimeImmutable $revealedAt;
    public readonly ?string $expiryDate;
    public readonly ?string $invoiceId;
    public readonly ?MaskedCardProduct $card;
    public readonly ?\DateTimeImmutable $purchasedAt;
    public readonly ?string $serialNumber;
    public readonly ?string $voucher;

    /**
     * Keeps partner-visible sale metadata and returned credentials immutable.
     */
    public function __construct(
        string $soldCardId,
        #[\SensitiveParameter]
        ?string $serialNumber = null,
        #[\SensitiveParameter]
        ?string $voucher = null,
        ?\DateTimeImmutable $revealedAt = null,
        ?string $expiryDate = null,
        ?string $invoiceId = null,
        ?MaskedCardProduct $card = null,
        ?\DateTimeImmutable $purchasedAt = null,
    ) {
        $this->soldCardId = $soldCardId;
        $this->serialNumber = $serialNumber;
        $this->voucher = $voucher;
        $this->revealedAt = $revealedAt;
        $this->expiryDate = $expiryDate;
        $this->invoiceId = $invoiceId;
        $this->card = $card;
        $this->purchasedAt = $purchasedAt;
    }

    /** Reads a protected reveal answer while preserving every supplied sale detail.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
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

    /** Serializes the wire credential for partner-owned storage; use diagnostic methods to redact it. */
    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'soldCardId' => $this->soldCardId,
            'serialNumber' => $this->serialNumber,
            'voucher' => $this->voucher,
            'revealedAt' => self::utc($this->revealedAt),
            'expiryDate' => $this->expiryDate,
            'invoiceId' => $this->invoiceId,
            'card' => $this->card?->jsonSerialize(),
            'purchasedAt' => self::utc($this->purchasedAt),
        ];
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

    private static function utc(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
