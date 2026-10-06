<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Holds the published key fields Anis uses to verify signed Partner responses. */
final readonly class PartnerJwk
{
    /**
     * Preserves optional JOSE fields while keeping the public key data immutable.
     *
     */
    public function __construct(
        public ?string $kty = null,
        public ?string $crv = null,
        public ?string $x = null,
        public ?string $y = null,
        public ?string $kid = null,
        public ?string $use = null,
        public ?string $alg = null,
        #[\SensitiveParameter]
        ?string $d = null,
    ) {
        $this->hasPrivateMember = $d !== null && $d !== '';
    }

    /** Indicates that the source document contained private key material without retaining it. */
    public bool $hasPrivateMember;

    /**
     * Reads a JSON key object and refuses field types that cannot represent a JOSE string.
     * @param array<array-key, mixed> $value
     */
    public static function fromArray(#[\SensitiveParameter] array $value): self
    {
        $strings = [];
        foreach (['kty', 'crv', 'x', 'y', 'kid', 'use', 'alg', 'd'] as $member) {
            $item = $value[$member] ?? null;
            if ($item !== null && !is_string($item)) {
                throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('A signing-key member has an invalid type.');
            }
            $strings[$member] = $item;
        }

        return new self(...$strings);
    }

    /**
     * Returns the present JOSE fields for canonical JSON and public-key processing.
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $values = [];
        foreach (['kty', 'crv', 'x', 'y', 'kid', 'use', 'alg'] as $member) {
            $value = $this->{$member};
            if ($value !== null) {
                $values[$member] = $value;
            }
        }

        return $values;
    }
}
