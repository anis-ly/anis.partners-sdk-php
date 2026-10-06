<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes the order state and any fields Anis returned for that state. */
final readonly class Order implements \JsonSerializable
{
    use WireJsonSerialization;
    /**
     * Keeps the order snapshot stable, including its optional delivered credentials.
     * @param list<RevealedCredential>|null $soldCards
     */
    public function __construct(
        public string $operationId,
        public OrderStatus $status = OrderStatus::Unknown,
        public ?string $invoiceId = null,
        public ?string $walletId = null,
        public ?string $cardId = null,
        public ?int $quantity = null,
        public ?Money $total = null,
        #[\SensitiveParameter]
        public ?array $soldCards = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?string $externalReference = null,
        public ?string $failureCode = null,
        public ?bool $codesWithheld = null,
    ) {}

    /**
     * Reads optional order fields as absent values, never fabricated defaults.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $total = ModelData::object($data, 'total');
        $soldCards = null;
        if (array_key_exists('soldCards', $data) && $data['soldCards'] !== null) {
            $soldCards = self::credentials(ModelData::objectList($data, 'soldCards'));
        }
        $withheld = $data['codesWithheld'] ?? null;
        if ($withheld !== null && !is_bool($withheld)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('The codesWithheld model member must be a boolean or null.');
        }

        return new self(
            ModelData::uuid($data, 'operationId'),
            OrderStatus::parse($data['status'] ?? null),
            ModelData::nullableUuid($data, 'invoiceId'),
            ModelData::nullableUuid($data, 'walletId'),
            ModelData::nullableUuid($data, 'cardId'),
            ModelData::nullableInteger($data, 'quantity'),
            $total === null ? null : Money::fromArray($total),
            $soldCards,
            ModelData::dateTime($data, 'completedAt'),
            ModelData::nullableString($data, 'externalReference'),
            ModelData::nullableString($data, 'failureCode'),
            $withheld,
        );
    }

    /** @param list<array<array-key, mixed>> $items
     *  @return list<RevealedCredential>
     */
    private static function credentials(#[\SensitiveParameter] array $items): array
    {
        $credentials = [];
        foreach ($items as $item) {
            $credentials[] = RevealedCredential::fromArray($item);
        }

        return $credentials;
    }

    /** Rebuilds a diagnostic order without its credential collection.
     * @param array<array-key, mixed> $properties
     */
    /** Hides credential-bearing sold-card details when an order is inspected. */
    public function __debugInfo(): array
    {
        return [
            'operationId' => $this->operationId,
            'status' => $this->status,
            'invoiceId' => $this->invoiceId,
            'walletId' => $this->walletId,
            'cardId' => $this->cardId,
            'quantity' => $this->quantity,
            'total' => $this->total,
            'soldCards' => $this->soldCards === null ? null : '<redacted>',
            'completedAt' => $this->completedAt,
            'externalReference' => $this->externalReference,
            'failureCode' => $this->failureCode,
            'codesWithheld' => $this->codesWithheld,
        ];
    }
}
