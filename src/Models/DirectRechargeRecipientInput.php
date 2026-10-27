<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Gives one recipient detail by its field key. */
final readonly class DirectRechargeRecipientInput implements \JsonSerializable
{
    public function __construct(public string $key, public string $value)
    {
        if ($key === '') {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A recipient detail needs a field key.');
        }
    }

    /** @return array{key: string, value: string} */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'value' => $this->value];
    }
}
