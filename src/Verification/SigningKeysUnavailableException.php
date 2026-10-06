<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\AnisPartnersException;

/** Reports that the published response-signing keys could not be fetched or read. */
final class SigningKeysUnavailableException extends \Anis\Partners\Errors\AnisPartnersRuntimeException implements AnisPartnersException
{
    /** Keeps the transport or document parsing cause available without exposing document contents. */
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The Anis signing-key document is unavailable.', 0, $previous);
    }
}
