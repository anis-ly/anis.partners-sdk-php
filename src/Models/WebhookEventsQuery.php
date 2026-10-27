<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Filters the notification deliveries of the application. */
final readonly class WebhookEventsQuery
{
    public function __construct(public ?WebhookDeliveryStatus $status = null, public ?\DateTimeImmutable $since = null, public ?string $cursor = null) {}
}
