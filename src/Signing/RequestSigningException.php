<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

use Anis\Partners\AnisPartnersException;

/** Reports that request signing failed before a request was sent, without exposing signed material. */
final class RequestSigningException extends \RuntimeException implements AnisPartnersException
{
    /**
     * Preserves the local cause while keeping base bytes, signatures, and key details out of the message.
     * Keeps these public partner values stable after construction.
     */
    public function __construct(?\Throwable $previous = null, bool $wrongLength = false)
    {
        $message = 'The request could not be signed and nothing was sent.';
        if ($wrongLength) {
            $message .= ' A 70–72 byte result is almost certainly DER; P-256 P1363 must be exactly 64 bytes.';
        }

        parent::__construct($message, 0, $previous);
    }
}
