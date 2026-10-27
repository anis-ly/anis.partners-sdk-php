<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\Page;
use Anis\Partners\Models\WebhookEvent;
use Anis\Partners\Models\WebhookEventAccepted;
use Anis\Partners\Models\WebhookEventsQuery;
use Anis\Partners\Signing\SignatureProfile;

/** Lists notification deliveries and asks Anis to send a missed one again. */
final class WebhookEventOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /**
     * Reads one page of deliveries, newest first; the page size is fixed by Anis.
     * @return Page<WebhookEvent>
     */
    public function getWebhookEventsPage(?WebhookEventsQuery $query = null): Page
    {
        $query ??= new WebhookEventsQuery();
        $path = $this->withQuery('v1/webhook-events', ['status' => $query->status?->value, 'since' => self::utc($query->since), 'cursor' => $query->cursor]);

        return $this->fetchPage($this->transport, '/v1/webhook-events', $path, [WebhookEvent::class, 'fromArray'], true);
    }

    /**
     * Walks every delivery page; the cursor in the query only sets where the walk starts.
     * @return \Generator<int, WebhookEvent>
     */
    public function getWebhookEvents(?WebhookEventsQuery $query = null): \Generator
    {
        $query ??= new WebhookEventsQuery();

        return $this->walk(fn(?string $cursor): Page => $this->getWebhookEventsPage(new WebhookEventsQuery($query->status, $query->since, $cursor)), $query->cursor);
    }

    /**
     * Asks Anis to deliver the same event again with its original body and id.
     *
     * This is a signed change sent once, never replayed for you: after a lost answer read the deliveries first.
     */
    public function redeliver(string $eventId): WebhookEventAccepted
    {
        $id = Uuid::canonical($eventId, 'event id');
        $response = $this->transport->request('POST', '/v1/webhook-events/{eventId}/redeliver', 'v1/webhook-events/' . $id . '/redeliver', SignatureProfile::BodylessNonceMutation, '{}');
        try {
            return WebhookEventAccepted::fromArray($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The verified redelivery answer does not match the accepted model.');
        }
    }
}
