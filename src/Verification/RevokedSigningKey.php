<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Identifies a compromised signing key that cannot authenticate an answer. */
final readonly class RevokedSigningKey
{
    public function __construct(public string $kid, public \DateTimeImmutable $compromisedAt) {}
}
