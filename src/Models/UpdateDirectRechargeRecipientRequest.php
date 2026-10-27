<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Corrects the recipient details of a direct recharge that is paused on wrong data. */
final readonly class UpdateDirectRechargeRecipientRequest implements \JsonSerializable
{
    /** @var list<DirectRechargeRecipientInput> */
    public array $recipient;

    /**
     * @param array<array-key, mixed> $recipient The recipient details as a non-empty list of DirectRechargeRecipientInput.
     */
    public function __construct(array $recipient)
    {
        if ($recipient === [] || !array_is_list($recipient)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A recipient update needs a list with at least one detail.');
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

    /** @return array{recipient: list<array{key: string, value: string}>} */
    public function toArray(): array
    {
        return ['recipient' => array_map(static fn(DirectRechargeRecipientInput $detail): array => $detail->jsonSerialize(), $this->recipient)];
    }

    /** @return array{recipient: list<array{key: string, value: string}>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        $json = ModelData::json($this->toArray());
        if (strlen($json) > CreateDirectRechargeRequest::MAX_BODY_BYTES) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A recipient update body may not exceed 8192 bytes.');
        }

        return $json;
    }
}
