<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one purchase using the catalogue price and an exact expected total. */
final readonly class CreateOrderRequest implements \JsonSerializable
{
    public int $quantity;
    public bool $useAllowedDebt;

    /**
     * Keeps the order quantity and consent flag exact because weak PHP callers could otherwise coerce them.
     * @param int $quantity The number of cards in the order.
     * @param bool $useAllowedDebt Whether the partner consents to using allowed debt.
     */
    public function __construct(
        public string $cardId,
        mixed $quantity,
        public Money $expectedUnitPrice,
        public Money $expectedTotal,
        public ?string $externalReference = null,
        mixed $useAllowedDebt = false,
    ) {
        $this->quantity = self::requireInteger($quantity);
        $this->useAllowedDebt = self::requireBoolean($useAllowedDebt);
    }

    /**
     * Hydrates a request while refusing incomplete values before any signed call is prepared.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $unitPrice = ModelData::object($data, 'expectedUnitPrice');
        $total = ModelData::object($data, 'expectedTotal');
        if ($unitPrice === null || $total === null || !array_key_exists('quantity', $data)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('An order request requires quantity and both prices.');
        }

        return new self(
            ModelData::uuid($data, 'cardId'),
            ModelData::integer($data, 'quantity'),
            Money::fromArray($unitPrice),
            Money::fromArray($total),
            ModelData::nullableString($data, 'externalReference'),
            ModelData::boolean($data, 'useAllowedDebt'),
        );
    }

    /**
     * Writes only contract members; null optional references are omitted because the gateway rejects extras.
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'cardId' => $this->cardId,
            'quantity' => $this->quantity,
            'expectedUnitPrice' => $this->expectedUnitPrice->toArray(),
            'expectedTotal' => $this->expectedTotal->toArray(),
        ];
        if ($this->externalReference !== null) {
            $data['externalReference'] = $this->externalReference;
        }
        $data['useAllowedDebt'] = $this->useAllowedDebt;

        return $data;
    }

    /**
     * Serializes only the request members accepted by the order route.
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Freezes the request JSON with the byte-stable escaping used by signature digests. */
    public function toJson(): string
    {
        return ModelData::json($this->toArray());
    }

    private static function requireInteger(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Order quantity must be an integer.');
        }

        return $value;
    }

    private static function requireBoolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('UseAllowedDebt consent must be a boolean.');
        }

        return $value;
    }
}
