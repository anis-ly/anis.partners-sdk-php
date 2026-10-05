<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports that the wallet balance cannot cover the purchase and nothing was charged. */
final class InsufficientBalanceException extends AnisApiException {}
