<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports that the requested card or quantity is no longer available. */
final class OutOfStockException extends AnisApiException {}
