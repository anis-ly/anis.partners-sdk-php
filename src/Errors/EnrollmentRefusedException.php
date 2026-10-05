<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports an invitation, challenge, proof, or enrollment key that Anis refused. */
final class EnrollmentRefusedException extends AnisApiException {}
