<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

use Anis\Partners\AnisPartnersException;

/** Reports a verified answer that cannot be safely mapped to a partner model. */
final class MalformedResponseException extends \RuntimeException implements AnisPartnersException
{
    /** Keeps response content out of the message and cause because it may contain credentials. */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
