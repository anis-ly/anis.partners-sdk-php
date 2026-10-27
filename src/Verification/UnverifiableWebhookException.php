<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\AnisPartnersException;

/** Reports a refused webhook delivery by its reason only; the body, headers and signature are never carried. */
final class UnverifiableWebhookException extends \Anis\Partners\Errors\AnisPartnersRuntimeException implements AnisPartnersException
{
    public function __construct(private readonly WebhookVerificationFailure $reason)
    {
        parent::__construct('The webhook delivery was refused because verification failed: ' . $reason->value . '. Its content must not be used.');
    }

    /** Returns the stable reason so callers need not parse a message. */
    public function failure(): WebhookVerificationFailure
    {
        return $this->reason;
    }
}
