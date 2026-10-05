<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports an access or owner policy that does not allow the requested action. */
final class AuthorizationException extends AnisApiException {}
