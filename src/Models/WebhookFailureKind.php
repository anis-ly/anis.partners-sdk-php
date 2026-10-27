<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Names why the last delivery attempt failed. */
enum WebhookFailureKind: string
{
    case Unknown = 'unknown';
    case HttpStatus = 'httpStatus';
    case Timeout = 'timeout';
    case Connect = 'connect';
    case Tls = 'tls';
    case TargetRefused = 'targetRefused';
    case ResponseTooLarge = 'responseTooLarge';
    case ChallengeMismatch = 'challengeMismatch';
    case ProjectionUnavailable = 'projectionUnavailable';
    case EndpointDisabled = 'endpointDisabled';
    case SigningUnavailable = 'signingUnavailable';

    /** Maps a value this SDK does not know to Unknown, which never means success or failure. */
    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }
}
