<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Carries signature fields and canonical request facts that must be sent together. */
final readonly class SignedRequestHeaders
{
    /**
     * Keeps wire headers and the signed base available as one immutable signing result.
     * Keeps these public partner values stable after construction.
     */
    public function __construct(
        public string $signatureInput,
        public string $signature,
        public string $anisDate,
        public ?string $contentDigest,
        public ?string $nonce,
        public ?string $idempotencyKey,
        public string $signatureBase,
    ) {}

    /** Hides request signatures and nonces from dumps and native debugging output. */
    public function __debugInfo(): array
    {
        return ['signature fields' => '<redacted>'];
    }

    /** Prevents accidental interpolation from exposing signed request material. */
    public function __toString(): string
    {
        return 'SignedRequestHeaders { <redacted> }';
    }
}
