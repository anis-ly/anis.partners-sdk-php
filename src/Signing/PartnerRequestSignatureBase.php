<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

use Anis\Partners\Internal\Uuid;

/** Renders the RFC 9421 request fields whose exact bytes Anis verifies. */
final class PartnerRequestSignatureBase
{
    public const LABEL = 'sig1';
    public const ALGORITHM = 'ecdsa-p256-sha256';
    public const MAX_SIGNATURE_LIFETIME_SECONDS = 300;

    /**
     * Renders signature parameters in protocol order; changing their spelling makes the signature unverifiable.
     * @param list<string> $components
     */
    public static function parameters(array $components, int $created, int $expires, string $keyId, ?string $nonce = null): string
    {
        if ($expires - $created > self::MAX_SIGNATURE_LIFETIME_SECONDS) {
            throw new \InvalidArgumentException('A request signature may live at most 300 seconds.');
        }
        if ($nonce !== null && ($nonce === '' || preg_match('/["\\\\\x00-\x1F\x7F]/', $nonce) === 1)) {
            throw new \InvalidArgumentException('A nonce must be a non-empty structured-field string without quotes, backslashes, or controls.');
        }

        $ids = array_map(static fn(string $component): string => '"' . $component . '"', $components);
        $parameters = '(' . implode(' ', $ids) . ');created=' . $created . ';expires=' . $expires
            . ';keyid="' . Uuid::canonical($keyId) . '";alg="' . self::ALGORITHM . '"';

        return $parameters . ($nonce === null ? '' : ';nonce="' . $nonce . '"');
    }

    /** Adds the protocol label required by the Signature-Input field. */
    public static function signatureInputHeader(string $parameters): string
    {
        return self::LABEL . '=' . $parameters;
    }

    /** Encodes the raw P1363 signature in the structured Signature field. */
    public static function signatureHeader(string $signature): string
    {
        return self::LABEL . '=:' . base64_encode($signature) . ':';
    }

    /**
     * Builds the byte-for-byte signature base, including its required final line.
     * @param list<string> $components
     */
    public static function build(array $components, SignatureInputs $inputs, string $parameters): string
    {
        $lines = [];
        foreach ($components as $component) {
            $lines[] = '"' . $component . '": ' . $inputs->valueOf($component) . "\n";
        }
        // The final line has no newline; adding one changes the bytes Anis verifies.
        $lines[] = '"@signature-params": ' . $parameters;

        return implode('', $lines);
    }
}
