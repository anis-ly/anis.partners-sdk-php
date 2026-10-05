<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Answers one enrollment challenge with proof of possession of the private key. */
final readonly class EnrollmentProofRequest implements \JsonSerializable
{
    /** Keeps these public partner values stable after construction. */
    public function __construct(
        public string $keyId,
        public int $challengeGeneration,
        public string $signature,
    ) {}

    /**
     * Reads the proof fields while normalizing the credential ID Anis issued.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $signature = ModelData::nullableString($data, 'signature');
        if ($signature === null || !array_key_exists('challengeGeneration', $data)) {
            throw new \UnexpectedValueException('An enrollment proof requires its generation and signature.');
        }

        return new self(
            ModelData::uuid($data, 'keyId'),
            ModelData::integer($data, 'challengeGeneration'),
            $signature,
        );
    }

    /**
     * Writes the three contract fields with a canonical credential ID.
     * @return array{keyId: string, challengeGeneration: int, signature: string}
     */
    public function toArray(): array
    {
        return ['keyId' => $this->keyId, 'challengeGeneration' => $this->challengeGeneration, 'signature' => $this->signature];
    }

    /**
     * Serializes the exact proof fields sent to the enrollment route.
     * @return array{keyId: string, challengeGeneration: int, signature: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Encodes the proof request without changing its signature bytes. */
    public function toJson(): string
    {
        return ModelData::json($this->toArray());
    }
}
