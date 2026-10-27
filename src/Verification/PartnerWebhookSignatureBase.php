<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Rebuilds the exact signature base of a webhook delivery from the values the receiver trusts. */
final class PartnerWebhookSignatureBase
{
    public const LABEL = 'sig1';
    public const ALGORITHM = 'ecdsa-p256-sha256';
    public const PROFILE = 'anis-partner-webhook-v1';
    public const LIFETIME_SECONDS = 300;
    public const SKEW_SECONDS = 300;
    public const MAX_ATTEMPT = 2147483647;

    /** @var list<string> */
    public const COMPONENTS = ['@method', '@target-uri', 'content-digest', 'webhook-id', 'anis-webhook-attempt', 'anis-application-id'];

    /**
     * Joins the six covered lines and the signature parameters with LF and no trailing newline.
     *
     * @param string $parameters The received parameter text starting at the component list, for example `("@method" ...);created=...`.
     */
    public static function build(string $targetUri, string $contentDigest, string $webhookId, string $attempt, string $applicationId, string $parameters): string
    {
        return "\"@method\": POST\n"
            . '"@target-uri": ' . $targetUri . "\n"
            . '"content-digest": ' . $contentDigest . "\n"
            . '"webhook-id": ' . $webhookId . "\n"
            . '"anis-webhook-attempt": ' . $attempt . "\n"
            . '"anis-application-id": ' . $applicationId . "\n"
            . '"@signature-params": ' . $parameters;
    }

    /** Writes the canonical covered-component list that the profile requires. */
    public static function componentList(): string
    {
        return '(' . implode(' ', array_map(static fn(string $name): string => '"' . $name . '"', self::COMPONENTS)) . ')';
    }
}
