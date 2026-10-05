<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports reuse of an operation ID with a different purchase request. */
final class IdempotencyConflictException extends AnisApiException {}
