<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports a request that does not satisfy a published input rule. */
final class ValidationFailedException extends AnisApiException {}
