<?php

declare(strict_types=1);

namespace Anis\Partners\Errors;

/** Reports that Anis has already received the same signed request bytes. */
final class ReplayDetectedException extends AnisApiException {}
