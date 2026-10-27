<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Anis\Partners\Models\DirectRecharge;
use Anis\Partners\Models\MeterCharge;
use Anis\Partners\Models\WebhookEventType;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\PartnerWebhookVerifier;
use Anis\Partners\Verification\UnverifiableWebhookException;
use Anis\Partners\Verification\VerifiableWebhook;
use GuzzleHttp\Client;
use Http\Discovery\Psr17FactoryDiscovery;

/**
 * Receives Anis notifications.
 *
 * Read the raw bytes before any JSON handling, verify, deduplicate on the event id in your own durable store, and only
 * then act. Meter tokens, recipient details, the challenge and the signature are never logged here.
 */
function receive(): int
{
    $authority = (string) getenv('ANIS_PARTNERS_AUTHORITY');
    $configuredUrl = (string) getenv('WEBHOOK_URL');
    $applicationId = (string) getenv('ANIS_APPLICATION_ID');
    $webhook = receivedWebhook(
        (string) getenv('WEBHOOK_ORIGIN'),
        $_SERVER,
        receivedHeaders(),
        (string) file_get_contents('php://input'),
    );
    $http = new Client(['timeout' => 10, 'allow_redirects' => false]);
    $keys = new HttpSigningKeySource($http, Psr17FactoryDiscovery::findRequestFactory(), $authority, 300);
    $verifier = new PartnerWebhookVerifier($keys, $configuredUrl, $applicationId);

    try {
        $envelope = $verifier->verifyAndParse($webhook);
    } catch (UnverifiableWebhookException $error) {
        error_log('Webhook refused: ' . $error->failure()->value);
        http_response_code(400);

        return 1;
    }

    // Answer the endpoint verification challenge only after the delivery itself verified.
    if ($envelope->type === WebhookEventType::EndpointVerification) {
        $challenge = $envelope->rawData['challenge'] ?? null;
        header('Content-Type: application/json');
        echo json_encode(['challenge' => is_string($challenge) ? $challenge : ''], JSON_THROW_ON_ERROR);

        return 0;
    }

    // Replace with an atomic insert into your own table keyed by the event id; a duplicate means it was handled already.
    if (alreadyHandled($envelope->id)) {
        http_response_code(200);

        return 0;
    }
    $data = $envelope->data;
    if ($data instanceof MeterCharge) {
        error_log(sprintf('Meter charge %s is %s', $data->operationId, $data->status->value));
    } elseif ($data instanceof DirectRecharge) {
        error_log(sprintf('Direct recharge %s is %s', $data->operationId, $data->status->value));
    }
    http_response_code(200);

    return 0;
}

/**
 * Combines the trusted public deployment origin with the path and query actually received.
 * WEBHOOK_ORIGIN is configuration (for example https://pos.example.ly), never Host or a forwarded header.
 * @param array<array-key, mixed> $server
 * @param array<string, string|list<string>> $headers
 */
function receivedWebhook(string $trustedOrigin, array $server, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body): VerifiableWebhook
{
    $method = $server['REQUEST_METHOD'] ?? '';
    $target = $server['REQUEST_URI'] ?? '';
    if (!is_string($target) || !str_starts_with($target, '/') || $trustedOrigin === '') {
        throw new InvalidArgumentException('Configure WEBHOOK_ORIGIN and capture the received request path and query.');
    }

    return new VerifiableWebhook(is_string($method) ? $method : '', rtrim($trustedOrigin, '/') . $target, $headers, $body);
}

/** @return array<string, string> */
function receivedHeaders(): array
{
    $headers = [];
    $received = getallheaders();
    foreach (is_array($received) ? $received : [] as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $headers[$name] = $value;
        }
    }

    return $headers;
}

function alreadyHandled(string $eventId): bool
{
    return false;
}

if (PHP_SAPI !== 'cli') {
    exit(receive());
}
