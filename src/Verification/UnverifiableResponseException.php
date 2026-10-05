<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\AnisPartnersException;

/** Reports a failed response check without retaining or printing untrusted response content. */
final class UnverifiableResponseException extends \RuntimeException implements AnisPartnersException
{
    /**
     * Creates a safe failure message from the specific rule the response did not satisfy.
     * Keeps these public partner values stable after construction.
     */
    public function __construct(private readonly ResponseVerificationFailure $reason)
    {
        parent::__construct('The Anis response was discarded because verification failed: ' . $reason->value . '.');
    }

    /** Returns the stable failure reason so callers need not parse a message. */
    public function failure(): ResponseVerificationFailure
    {
        return $this->reason;
    }
}
