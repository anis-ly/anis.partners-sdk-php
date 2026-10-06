<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

use Anis\Partners\Models\Problem;

/** Distinguishes a verified empty success from a partner refusal without inspecting message text. */
final class EmptyBodyException extends AnisApiException
{
    public function __construct(int $status)
    {
        parent::__construct(new Problem('about:blank', 'Empty body', $status, ErrorCode::InternalError->value), $status);
    }
}
