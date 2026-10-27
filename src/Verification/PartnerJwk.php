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
        public ?string $status = null,
        public ?\DateTimeImmutable $notBefore = null,
        public ?\DateTimeImmutable $retiredAt = null,
        public ?string $purpose = null,
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
        foreach (['kty', 'crv', 'x', 'y', 'kid', 'use', 'alg', 'd', 'purpose'] as $member) {
            $item = $value[$member] ?? null;
            if ($item !== null && !is_string($item)) {
                throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('A signing-key member has an invalid type.');
            }
            $strings[$member] = $item;
        }

        $status = $value['status'] ?? null;
        if ($status !== null && !in_array($status, ['active', 'next', 'retired'], true)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('A signing-key status is invalid.');
        }
        try {
            $notBefore = \Anis\Partners\Models\ModelData::dateTime($value, 'notBefore');
            $retiredAt = \Anis\Partners\Models\ModelData::dateTime($value, 'retiredAt');
            $rawNotBefore = \Anis\Partners\Models\ModelData::nullableString($value, 'notBefore');
            if ($notBefore !== null && $rawNotBefore !== null
                && preg_match('/\.\d{6}(\d+)(?:Z|[+-]\d{2}:\d{2})\z/D', $rawNotBefore, $fraction) === 1
                && strspn($fraction[1], '0') !== strlen($fraction[1])) {
                // Activation must never move earlier when PHP truncates a fraction finer than one microsecond.
                $notBefore = $notBefore->modify('+1 microsecond');
            }
        } catch (\Anis\Partners\Errors\AnisPartnersUnexpectedValueException) {
            // Invalid lifecycle metadata makes this key unusable; verifiers report key_rejected instead of trusting it.
            $notBefore = null;
            $retiredAt = null;
        }

        return new self(...[...$strings, 'status' => $status, 'notBefore' => $notBefore, 'retiredAt' => $retiredAt]);
    }

    /**
     * Returns the present JOSE fields for canonical JSON and public-key processing.
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $values = [];
        foreach (['kty', 'crv', 'x', 'y', 'kid', 'use', 'alg', 'purpose'] as $member) {
            $value = $this->{$member};
            if ($value !== null) {
                $values[$member] = $value;
            }
        }

        return $values;
    }
}
