<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports that Anis could not reach a final decision for this call. */
final class DependencyUnavailableException extends AnisApiException {}
