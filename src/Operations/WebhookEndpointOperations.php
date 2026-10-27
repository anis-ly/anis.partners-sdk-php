<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Models\SetWebhookEndpointRequest;
use Anis\Partners\Models\WebhookEndpoint;
use Anis\Partners\Models\WebhookEventAccepted;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureProfile;

/** Sets the application's notification address and asks Anis to send a test. */
final class WebhookEndpointOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Reads the endpoint; a null url and notConfigured status mean none is set. */
    public function get(): WebhookEndpoint
    {
        /** @var WebhookEndpoint */
        return $this->fetchModel($this->transport, '/v1/webhook-endpoint', 'v1/webhook-endpoint', [WebhookEndpoint::class, 'fromArray']);
    }

    /**
     * Sets the endpoint. Anis then challenges the address; it is verified only after the receiver echoes the challenge.
     *
     * This is a signed change sent once, never replayed for you: after a lost answer read the endpoint first.
     */
    public function set(SetWebhookEndpointRequest $request): WebhookEndpoint
    {
        try {
            $body = $request->toJson();
        } catch (\Throwable $exception) {
            throw new RequestSigningException($exception);
        }
        $response = $this->transport->request('PUT', '/v1/webhook-endpoint', 'v1/webhook-endpoint', SignatureProfile::BodylessNonceMutation, $body);
        try {
            return WebhookEndpoint::fromArray($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The verified endpoint answer does not match the endpoint model.');
        }
    }

    /** Asks Anis to send a test notification to the verified, enabled endpoint; the body is exactly `{}`. */
    public function test(): WebhookEventAccepted
    {
        $response = $this->transport->request('POST', '/v1/webhook-endpoint/test', 'v1/webhook-endpoint/test', SignatureProfile::BodylessNonceMutation, '{}');
        try {
            return WebhookEventAccepted::fromArray($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The verified test answer does not match the accepted model.');
        }
    }
}
