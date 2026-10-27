<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Models\WebhookDeliveryStatus;
use Anis\Partners\Models\WebhookEventsQuery;
use Anis\Partners\Models\WebhookEventType;
use Anis\Partners\Models\WebhookFailureKind;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookEventOperationsTest extends TestCase
{
    private const EVENT = '0f8c3e1a-6c7d-5b1e-9a40-2a1f5c9e7d11';

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    #[Test]
    public function it_lists_deliveries_with_the_exact_query_and_all_eight_members(): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->body = '{"items":[{"id":"' . self::EVENT . '","type":"meterCharge.succeeded","status":"failed","attempts":12,"lastAttemptAt":"2026-10-23T08:00:00Z","nextAttemptAt":null,"lastResponseCode":503,"lastFailureKind":"httpStatus"},{"id":"1f8c3e1a-6c7d-5b1e-9a40-2a1f5c9e7d11","type":"future.type","status":"delivering","attempts":0,"lastAttemptAt":null,"nextAttemptAt":null,"lastResponseCode":null,"lastFailureKind":null}],"nextCursor":null}';
        $page = self::client($wire)->webhookEvents()->getWebhookEventsPage(new WebhookEventsQuery(WebhookDeliveryStatus::Failed, new \DateTimeImmutable('2026-10-19T00:00:00Z'), 'a+b'));

        self::assertSame('status=failed&since=2026-10-19T00%3A00%3A00Z&cursor=a%2Bb', $wire->requests[0]->getUri()->getQuery());
        self::assertCount(1, $wire->requests);
        self::assertSame(WebhookEventType::MeterChargeSucceeded, $page->items[0]->type);
        self::assertSame(WebhookFailureKind::HttpStatus, $page->items[0]->lastFailureKind);
        self::assertSame(12, $page->items[0]->attempts);
        self::assertSame(WebhookEventType::Unknown, $page->items[1]->type);
        self::assertSame('future.type', $page->items[1]->rawType);
        self::assertNull($page->nextCursor);
        $json = $page->items[1]->jsonSerialize();
        self::assertArrayHasKey('lastFailureKind', $json);
        self::assertNull($json['lastFailureKind']);
        self::assertCount(8, $json);
        self::assertSame('future.type', $json['type']);
    }

    #[Test]
    public function it_reads_an_empty_page_and_walks_cursors(): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->body = '{"items":[],"nextCursor":null}';
        self::assertSame([], iterator_to_array(self::client($wire)->webhookEvents()->getWebhookEvents(), false));
        self::assertSame('', $wire->requests[0]->getUri()->getQuery());
    }

    #[Test]
    public function it_redelivers_with_an_exact_empty_object_and_no_idempotency_key(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 202;
        $wire->body = '{"id":"' . self::EVENT . '","status":"pending","queuedAt":"2026-10-23T08:01:00Z"}';
        $accepted = self::client($wire)->webhookEvents()->redeliver(self::EVENT);

        $sent = $wire->requests[0];
        self::assertSame('/v1/webhook-events/' . self::EVENT . '/redeliver', $sent->getUri()->getPath());
        self::assertSame('{}', (string) $sent->getBody());
        self::assertSame(ContentDigest::of('{}'), $sent->getHeaderLine('Content-Digest'));
        self::assertSame('', $sent->getHeaderLine('Idempotency-Key'));
        self::assertSame(self::EVENT, $accepted->id);
        self::assertNull($accepted->type);
    }

    #[Test]
    public function it_does_not_replay_a_redelivery_after_a_lost_answer(): void
    {
        $wire = new SignedFakeWire();
        $wire->failure = new \RuntimeException('connection dropped');
        try {
            self::client($wire)->webhookEvents()->redeliver(self::EVENT);
            self::fail('A lost answer must surface.');
        } catch (\RuntimeException) {
            self::assertCount(1, $wire->requests);
        }
    }
}
