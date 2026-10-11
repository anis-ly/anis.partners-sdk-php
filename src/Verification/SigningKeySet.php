<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Holds the signing keys published by Anis for response verification. */
final readonly class SigningKeySet
{
    /**
     * Keeps the current published key list immutable while a response is being checked.
     * @param list<PartnerJwk> $keys
     * @param list<RevokedSigningKey> $revoked
     */
    public function __construct(public array $keys, public array $revoked = []) {}

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

        $revoked = [];
        foreach (\Anis\Partners\Models\ModelData::objectList($document, 'revoked') as $key) {
            $kid = \Anis\Partners\Models\ModelData::nullableString($key, 'kid');
            $at = \Anis\Partners\Models\ModelData::dateTime($key, 'compromisedAt');
            if ($kid === null || $kid === '' || $at === null) {
                throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('A revoked signing-key entry is incomplete.');
            }
            $revoked[] = new RevokedSigningKey($kid, $at);
        }
        return new self($keys, $revoked);
    }
}
