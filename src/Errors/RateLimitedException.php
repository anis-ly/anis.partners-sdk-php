<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports a request limit and exposes Retry-After when Anis provides it. */
final class RateLimitedException extends AnisApiException {}
