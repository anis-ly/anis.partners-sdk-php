<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

use Anis\Partners\Verification\PartnerJwk;

/** Submits a public key and the validity window requested for its enrollment. */
final readonly class EnrollmentKeyRequest implements \JsonSerializable
{
    public function __construct(
        public PartnerJwk $publicJwk,
        public \DateTimeImmutable $notBefore,
        public \DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Reads the public key and both required times so a partial submission cannot be sent.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $jwk = ModelData::object($data, 'publicJwk');
        $notBefore = ModelData::dateTime($data, 'notBefore');
        $expiresAt = ModelData::dateTime($data, 'expiresAt');
        if ($jwk === null || $notBefore === null || $expiresAt === null) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('An enrollment key request requires a public key and both dates.');
        }

        return new self(PartnerJwk::fromArray($jwk), $notBefore, $expiresAt);
    }

    /**
     * Writes only the published public-key and date members in UTC.
     * @return array{publicJwk: array<string, string>, notBefore: string, expiresAt: string}
     */
    public function toArray(): array
    {
        $jwk = [];
        foreach (['kty', 'crv', 'x', 'y'] as $member) {
            $value = $this->publicJwk->{$member};
            if ($value === null) {
                throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('An enrollment request must contain a complete public P-256 JWK.');
            }
            $jwk[$member] = $value;
        }

        return [
            'publicJwk' => $jwk,
            'notBefore' => $this->notBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'expiresAt' => $this->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Serializes the public JWK and UTC validity window accepted by enrollment.
     * @return array{publicJwk: array<string, string>, notBefore: string, expiresAt: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Encodes the exact request fields with the shared signed-body escaping rules. */
    public function toJson(): string
    {
        return ModelData::json($this->toArray());
    }
}
