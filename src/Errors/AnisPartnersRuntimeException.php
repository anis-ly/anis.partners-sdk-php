<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

use Anis\Partners\AnisPartnersException;

/** Marks an SDK input failure so one catch can handle SDK-raised errors. */
class AnisPartnersRuntimeException extends \RuntimeException implements AnisPartnersException {}
