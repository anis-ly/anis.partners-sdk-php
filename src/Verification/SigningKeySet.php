<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Holds the signing keys published by Anis for response verification. */
final readonly class SigningKeySet
{
    /**
     * Keeps the current published key list immutable while a response is being checked.
     * @param list<PartnerJwk> $keys
     */
    public function __construct(public array $keys) {}

    /** Parses the key document and refuses invalid JSON rather than verifying against partial data. */
    public static function fromJson(string $json): self
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('The signing-key document is not valid JSON.', 0, $error);
        }
        if (!is_array($document) || !is_array($document['keys'] ?? null)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('The signing-key document has no keys array.');
        }
        $keys = [];
        foreach ($document['keys'] as $key) {
            if (!is_array($key)) {
                throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('A signing-key entry is not an object.');
            }
            $keys[] = PartnerJwk::fromArray($key);
        }

        return new self($keys);
    }
}
