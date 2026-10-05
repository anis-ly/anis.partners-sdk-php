<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Selects the exact request components Anis requires each request kind to cover. */
enum SignatureProfile: string
{
    case SafeRead = 'SafeRead';
    case BodylessNonceMutation = 'BodylessNonceMutation';
    case OrderMutation = 'OrderMutation';

    /**
     * Returns the ordered covered components; changing the order changes the signature Anis verifies.
     * @return list<string>
     */
    public function components(): array
    {
        return match ($this) {
            self::SafeRead => ['@method', '@authority', '@path', '@query', 'x-anis-date'],
            self::BodylessNonceMutation => ['@method', '@authority', '@path', '@query', 'content-digest', 'nonce', 'x-anis-date'],
            self::OrderMutation => ['@method', '@authority', '@path', '@query', 'content-digest', 'nonce', 'idempotency-key', 'x-anis-date'],
        };
    }
}
