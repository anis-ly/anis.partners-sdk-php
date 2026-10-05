<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports an owner allowance limit that cannot be cleared by polling. */
final class LimitExceededException extends AnisApiException {}
