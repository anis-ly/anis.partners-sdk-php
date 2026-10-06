<?php

declare(strict_types=1);

namespace Anis\Partners\Enrollment;

use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Signing\P256Signer;

/** Builds the possession proof Anis checks when a public key is enrolled. */
final class EnrollmentProof
{
    /** Separates enrollment proof bytes from request signatures and any other signed messages. */
    public const DOMAIN_SEPARATOR = 'anis.partners.v2.credential-proof';

    /** Builds the shared line format from values both sides hold without disclosing the original challenge. */
    public static function message(string $keyId, int $challengeGeneration, #[\SensitiveParameter] string $challenge, string $thumbprint): string
    {
        // Anis stores only the challenge hash; the shared line format proves the same challenge without sending it.
        $challengeHash = hash('sha256', $challenge);

        return self::DOMAIN_SEPARATOR . "\n" . Uuid::canonical($keyId, 'key id') . "\n" . $challengeGeneration . "\n"
            . $challengeHash . "\n" . $thumbprint;
    }

    /** Returns the base64url P1363 proof Anis verifies against the submitted public key. */
    public static function signature(#[\SensitiveParameter] string $message, P256Signer $signer): string
    {
        $signature = $signer->sign($message);
        if (strlen($signature) !== 64) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('An enrollment proof requires a 64-byte P-256 P1363 signature.');
        }

        return Base64Url::encode($signature);
    }
}
