<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports credentials or a signature that Anis could not authenticate. */
final class InvalidCredentialsException extends AnisApiException {}
