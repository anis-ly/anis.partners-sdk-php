<?php

declare(strict_types=1);

namespace Anis\Partners\Enrollment;

use Anis\Partners\AnisPartnersException;

/** Stops enrollment when Anis reports a different public key than the one submitted. */
final class EnrollmentKeyMismatchException extends \RuntimeException implements AnisPartnersException
{
    /** Preserves the two fingerprints for local diagnosis without allowing proof on an untrusted challenge. */
    public function __construct(public readonly string $localThumbprint, public readonly ?string $serverThumbprint)
    {
        parent::__construct('The key Anis holds differs from the key submitted. Do not prove possession; ask Anis staff to restart enrollment.');
    }
}
