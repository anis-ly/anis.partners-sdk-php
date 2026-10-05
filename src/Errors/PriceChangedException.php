<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports that the catalogue price changed before the order was accepted. */
final class PriceChangedException extends AnisApiException {}
