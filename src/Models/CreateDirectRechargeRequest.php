<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes one direct recharge; the operation id travels only in the Idempotency-Key header and Anis fills the prices itself. */
final readonly class CreateDirectRechargeRequest implements \JsonSerializable
{
    /** Keeps the largest body Anis accepts for a money-operation create. */
    public const MAX_BODY_BYTES = 8192;

    public string $productId;

    /** @var list<DirectRechargeRecipientInput> */
    public array $recipient;

    /**
     * @param string $payoutAmount The amount paid out in the product currency, as a decimal string with three places.
     * @param array<array-key, mixed> $recipient The recipient details as a list of DirectRechargeRecipientInput.
     */
    public function __construct(
        string $productId,
        public string $payoutAmount,
        public Money $expectedTotal,
        array $recipient,
        public ?string $note = null,
        public ?string $externalReference = null,
    ) {
        $this->productId = \Anis\Partners\Internal\Uuid::canonical($productId, 'product id');
        if (preg_match('/\A\d+\.\d{3}\z/D', $payoutAmount) !== 1 || preg_match('/[1-9]/', $payoutAmount) !== 1) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('PayoutAmount must be a positive decimal string with three places.');
        }
        if ($expectedTotal->thousandths <= 0) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('ExpectedTotal must be greater than zero.');
        }
        if (!array_is_list($recipient)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The recipient details must be a list.');
        }
        $details = [];
        foreach ($recipient as $detail) {
            if (!$detail instanceof DirectRechargeRecipientInput) {
                throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Every recipient detail must be a DirectRechargeRecipientInput.');
            }
            $details[] = $detail;
        }
        $this->recipient = $details;
    }

    /**
     * Rebuilds a request from its stored wire form, for example from the journal written before the create.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $recipient = [];
        foreach (ModelData::requiredObjectList($data, 'recipient') as $detail) {
            $recipient[] = new DirectRechargeRecipientInput(ModelData::requiredString($detail, 'key'), ModelData::requiredString($detail, 'value'));
        }

        return new self(
            ModelData::requiredUuid($data, 'productId'),
            ModelData::requiredString($data, 'payoutAmount'),
            Money::fromArray(ModelData::requiredObject($data, 'expectedTotal')),
            $recipient,
            ModelData::nullableString($data, 'note'),
            ModelData::nullableString($data, 'externalReference'),
        );
    }

    /**
     * Writes only the contract members; absent optional members are omitted and no price member exists.
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'productId' => $this->productId,
            'payoutAmount' => $this->payoutAmount,
            'expectedTotal' => $this->expectedTotal->toArray(),
            'recipient' => array_map(static fn(DirectRechargeRecipientInput $detail): array => $detail->jsonSerialize(), $this->recipient),
        ];
        if ($this->note !== null) {
            $data['note'] = $this->note;
        }
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
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A direct recharge body may not exceed 8192 bytes.');
        }

        return $json;
    }
}
