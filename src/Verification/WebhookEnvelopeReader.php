<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\Models\DirectRecharge;
use Anis\Partners\Models\MeterCharge;
use Anis\Partners\Models\ModelData;
use Anis\Partners\Models\WebhookEnvelope;
use Anis\Partners\Models\WebhookEventType;

/** @internal Reads the authenticated body of a webhook into an envelope, refusing anything that is not one. */
final class WebhookEnvelopeReader
{
    /**
     * Requires an object with unique root members, a UUID id and applicationId, a type, an RFC 3339 occurredAt and object data.
     *
     * @throws UnverifiableWebhookException
     */
    public static function read(#[\SensitiveParameter] string $body, string $webhookId, string $headerApplicationId): WebhookEnvelope
    {
        $members = self::rootMembers($body);
        if ($members === null || !isset($members['data'])) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data']) || $body[$members['data'][0]] !== '{') {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        $type = $decoded['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        try {
            $id = self::requiredUuid($decoded, 'id');
            $applicationId = self::requiredUuid($decoded, 'applicationId');
            $occurredAt = ModelData::dateTime($decoded, 'occurredAt');
        } catch (\Throwable) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        if ($occurredAt === null) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        if ($id !== strtolower($webhookId)) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EventIdMismatch);
        }
        if ($applicationId !== strtolower($headerApplicationId)) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::ApplicationIdMismatch);
        }
        $knownType = WebhookEventType::parse($type);
        $data = $decoded['data'];
        $typed = null;
        try {
            if ($knownType->isOperational()) {
                $typed = str_starts_with($knownType->value, 'meterCharge.') ? MeterCharge::fromArray($data) : DirectRecharge::fromArray($data);
            }
        } catch (\Throwable) {
            throw new UnverifiableWebhookException(WebhookVerificationFailure::EnvelopeInvalid);
        }
        $raw = substr($body, $members['data'][0], $members['data'][1] - $members['data'][0]);

        return new WebhookEnvelope($id, $knownType, $type, $occurredAt, $applicationId, $typed, $data, $raw);
    }

    /** @param array<array-key, mixed> $decoded */
    private static function requiredUuid(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('not a string');
        }

        return \Anis\Partners\Internal\Uuid::canonical($value);
    }

    /**
     * Lists the root members of a JSON object with the byte range of each value, or null when a name repeats or the text is not an object.
     *
     * @return array<string, array{int, int}>|null
     */
    private static function rootMembers(string $json): ?array
    {
        $length = strlen($json);
        $i = self::skipSpace($json, 0);
        if ($i >= $length || $json[$i] !== '{') {
            return null;
        }
        $i = self::skipSpace($json, $i + 1);
        $members = [];
        if ($i < $length && $json[$i] === '}') {
            return $members;
        }
        while ($i < $length) {
            if ($json[$i] !== '"') {
                return null;
            }
            $end = self::stringEnd($json, $i);
            if ($end === null) {
                return null;
            }
            $name = json_decode(substr($json, $i, $end - $i + 1));
            if (!is_string($name) || isset($members[$name])) {
                return null;
            }
            $i = self::skipSpace($json, $end + 1);
            if ($i >= $length || $json[$i] !== ':') {
                return null;
            }
            $start = self::skipSpace($json, $i + 1);
            $valueEnd = self::valueEnd($json, $start);
            if ($valueEnd === null) {
                return null;
            }
            $members[$name] = [$start, $valueEnd];
            $i = self::skipSpace($json, $valueEnd);
            if ($i >= $length) {
                return null;
            }
            if ($json[$i] === '}') {
                return $members;
            }
            if ($json[$i] !== ',') {
                return null;
            }
            $i = self::skipSpace($json, $i + 1);
        }

        return null;
    }

    private static function skipSpace(string $json, int $i): int
    {
        $length = strlen($json);
        while ($i < $length && ($json[$i] === ' ' || $json[$i] === "\t" || $json[$i] === "\n" || $json[$i] === "\r")) {
            $i++;
        }

        return $i;
    }

    /** Returns the index of the closing quote of the string that opens at $i. */
    private static function stringEnd(string $json, int $i): ?int
    {
        $length = strlen($json);
        for ($j = $i + 1; $j < $length; $j++) {
            if ($json[$j] === '\\') {
                $j++;
            } elseif ($json[$j] === '"') {
                return $j;
            }
        }

        return null;
    }

    /** Returns the index just after the value that starts at $i, without validating it; json_decode validates the whole text. */
    private static function valueEnd(string $json, int $i): ?int
    {
        $length = strlen($json);
        if ($i >= $length) {
            return null;
        }
        if ($json[$i] === '"') {
            $end = self::stringEnd($json, $i);

            return $end === null ? null : $end + 1;
        }
        if ($json[$i] === '{' || $json[$i] === '[') {
            $depth = 0;
            for ($j = $i; $j < $length; $j++) {
                $char = $json[$j];
                if ($char === '"') {
                    $end = self::stringEnd($json, $j);
                    if ($end === null) {
                        return null;
                    }
                    $j = $end;
                } elseif ($char === '{' || $char === '[') {
                    $depth++;
                } elseif ($char === '}' || $char === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return $j + 1;
                    }
                }
            }

            return null;
        }
        $j = $i;
        while ($j < $length && !in_array($json[$j], [',', '}', ' ', "\t", "\n", "\r"], true)) {
            $j++;
        }

        return $j;
    }
}
