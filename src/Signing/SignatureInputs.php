<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

use Anis\Partners\Internal\Uuid;

/** Holds request facts in their signed form so headers and the signature base cannot diverge. */
final readonly class SignatureInputs
{
    public string $method;
    public string $authority;
    public ?string $idempotencyKey;

    /**
     * Canonicalizes shared wire values once; later normalization can sign values different from those sent.
     * Keeps these public partner values stable after construction.
     */
    public function __construct(
        string $method,
        string $authority,
        public string $path,
        public string $canonicalQuery,
        public string $anisDate,
        public ?string $contentDigest = null,
        public ?string $nonce = null,
        ?string $idempotencyKey = null,
    ) {
        if (str_contains($path, '%')) {
            throw new \InvalidArgumentException('Signed paths cannot contain percent encoding.');
        }

        $this->method = strtoupper($method);
        $this->authority = strtolower($authority);
        $this->idempotencyKey = $idempotencyKey === null ? null : Uuid::canonical($idempotencyKey);
    }

    /** Returns one exact component value or refuses missing and unrecognized components. */
    public function valueOf(string $component): string
    {
        return match ($component) {
            '@method' => $this->method,
            '@authority' => $this->authority,
            '@path' => $this->path,
            // Keeping '?' distinguishes a missing query from an explicitly empty query.
            '@query' => '?' . $this->canonicalQuery,
            'content-digest' => $this->contentDigest ?? throw self::missing($component),
            'nonce' => $this->nonce ?? throw self::missing($component),
            'idempotency-key' => $this->idempotencyKey ?? throw self::missing($component),
            'x-anis-date' => $this->anisDate,
            default => throw new \InvalidArgumentException('Not a covered component of an Anis signature profile.'),
        };
    }

    private static function missing(string $component): \InvalidArgumentException
    {
        return new \InvalidArgumentException("The covered component '{$component}' has no value.");
    }
}
