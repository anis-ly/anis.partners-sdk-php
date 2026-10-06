<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

use Anis\Partners\AnisPartnersException;

/** Marks an SDK input failure so one catch can handle SDK-raised errors. */
final class AnisPartnersOverflowException extends \OverflowException implements AnisPartnersException {}
