<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Rebuilds the exact response-signature bytes covered by the Anis response contract. */
final class PartnerResponseSignatureBase
{
    public const LABEL = 'sig1';
    public const ALGORITHM = 'ecdsa-p256-sha256';
    public const PROFILE = 'partner-response-v1';
    public const MAX_AGE_SECONDS = 60;

    /**
     * Selects fields in contract order so unsigned response fields cannot be substituted.
     * @return list<array{identifier: string, value: string}>
     */
    public static function components(
        int $status,
        string $contentDigest,
        string $requestId,
        ?string $requestSignatureInput,
        ?string $location,
        ?string $retryAfter,
        ?string $idempotencyReplayed,
        ?string $cacheControl,
    ): array {
        $components = [
            ['identifier' => '"@status"', 'value' => (string) $status],
            ['identifier' => '"content-digest"', 'value' => $contentDigest],
            ['identifier' => '"x-request-id"', 'value' => $requestId],
        ];
        if ($requestSignatureInput !== null && $requestSignatureInput !== '') {
            $components[] = ['identifier' => '"signature-input";req', 'value' => $requestSignatureInput];
        }
        foreach ([
            'location' => $location,
            'retry-after' => $retryAfter,
            'idempotency-replayed' => $idempotencyReplayed,
            'cache-control' => $cacheControl,
        ] as $name => $value) {
            if ($value !== null && $value !== '') {
                $components[] = ['identifier' => '"' . $name . '"', 'value' => $value];
            }
        }

        return $components;
    }

    /**
     * Renders signature parameters including the published key identifier.
     * @param list<array{identifier: string, value: string}> $components
     */
    public static function parameters(array $components, int $created, string $keyId): string
    {
        return '(' . implode(' ', array_column($components, 'identifier')) . ');created=' . $created
            . ';keyid="' . $keyId . '";alg="' . self::ALGORITHM . '"';
    }

    /**
     * Produces the Signature-Input field paired with this response base.
     * @param list<array{identifier: string, value: string}> $components
     */
    public static function signatureInputHeader(array $components, int $created, string $keyId): string
    {
        return self::LABEL . '=' . self::parameters($components, $created, $keyId);
    }

    /**
     * Builds the precise bytes that the response signature authenticates.
     * @param list<array{identifier: string, value: string}> $components
     */
    public static function build(array $components, int $created, string $keyId): string
    {
        $lines = [];
        foreach ($components as $component) {
            $lines[] = $component['identifier'] . ': ' . $component['value'] . "\n";
        }
        $lines[] = '"@signature-params": ' . self::parameters($components, $created, $keyId);

        return implode('', $lines);
    }
}
