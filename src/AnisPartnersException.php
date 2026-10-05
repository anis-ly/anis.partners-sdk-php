<?php

declare(strict_types=1);

namespace Anis\Partners;

/** Marks failures raised by the SDK so applications can handle them with one catch clause. */
interface AnisPartnersException extends \Throwable {}
