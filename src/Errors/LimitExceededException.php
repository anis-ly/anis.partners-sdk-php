<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports a spending allowance that is used up; the order is closed. For a spending cap, $retryAfter says when a new order can succeed. */
final class LimitExceededException extends AnisApiException {}
