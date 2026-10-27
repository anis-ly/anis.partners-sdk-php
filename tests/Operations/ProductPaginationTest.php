<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Errors\ValidationFailedException;
use Anis\Partners\Models\DirectRechargesQuery;
use Anis\Partners\Models\DirectRechargeStatus;
use Anis\Partners\Models\MeterChargesQuery;
use Anis\Partners\Models\MeterChargeStatus;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductPaginationTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function resources(): iterable
    {
        yield 'meter charges' => [true];
        yield 'direct recharges' => [false];
    }

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();
        $wire->signResponses = false;

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    /** @return array<string, mixed> */
    private static function fixture(bool $meter): array
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/fixtures/products/' . ($meter ? 'meter-charges' : 'direct-recharges') . '.json');
        self::assertNotFalse($bytes);
        $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['items']);
        self::assertCount(1, $data['items']);

        return ['items' => $data['items'], 'nextCursor' => $data['nextCursor']];
    }

    #[Test]
    #[DataProvider('resources')]
    public function it_preserves_all_filters_and_fractional_bounds_through_an_empty_intermediate_page(bool $meter): void
    {
        $wire = new SignedFakeWire();
        $fixture = self::fixture($meter);
        $first = $fixture;
        $first['nextCursor'] = 'opaque+/=A';
        $wire->responseQueue = [
            ['status' => 200, 'body' => P::json($first), 'headers' => []],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"opaque+/=B"}', 'headers' => []],
            ['status' => 200, 'body' => P::json($fixture), 'headers' => []],
        ];
        $client = self::client($wire);
        $from = new \DateTimeImmutable('2026-10-20T10:00:00.900000+02:00');
        $to = new \DateTimeImmutable('2026-10-20T08:00:00.950000Z');
        $items = iterator_to_array($meter
            ? $client->meterCharges()->getMeterCharges(P::WALLET, new MeterChargesQuery(MeterChargeStatus::Succeeded, '0268000710262', $from, $to, 'start+/=', 25))
            : $client->directRecharges()->getDirectRecharges(P::WALLET, new DirectRechargesQuery(DirectRechargeStatus::Completed, P::PRODUCT, $from, $to, 'start+/=', 25)), false);
        self::assertCount(2, $items);
        self::assertCount(3, $wire->requests);
        $expected = $meter ? 'status=succeeded&meterNumber=0268000710262' : 'status=completed&productId=' . P::PRODUCT;
        $expected .= '&from=2026-10-20T08%3A00%3A00.900000Z&to=2026-10-20T08%3A00%3A00.950000Z&cursor=';
        foreach (['start+/=', 'opaque+/=A', 'opaque+/=B'] as $index => $cursor) {
            self::assertSame($expected . rawurlencode($cursor) . '&pageSize=25', $wire->requests[$index]->getUri()->getQuery());
            self::assertSame('/v1/wallets/' . P::WALLET . '/' . ($meter ? 'meter-charges' : 'direct-recharges'), $wire->requests[$index]->getUri()->getPath());
        }
        foreach ($items as $item) {
            self::assertSame($meter ? P::METER_OPERATION : P::RECHARGE_OPERATION, $item->operationId);
            self::assertSame('LYD', $item->total->currency);
            self::assertSame($meter ? '97.500' : '237.500', $item->total->amount());
        }
    }

    /** @return iterable<string, array{bool, ?string, list<string>}> */
    public static function cursorRuns(): iterable
    {
        foreach ([true, false] as $meter) {
            $name = $meter ? 'meter' : 'recharge';
            yield "$name repeated" => [$meter, null, ['A', 'A']];
            yield "$name cycle" => [$meter, null, ['A', 'B', 'A']];
            yield "$name initial cursor repeats" => [$meter, 'A', ['A']];
        }
    }

    /** @param list<string> $cursors */
    #[Test]
    #[DataProvider('cursorRuns')]
    public function it_stops_repeated_and_cyclic_cursors_without_another_request(bool $meter, ?string $initial, array $cursors): void
    {
        $wire = new SignedFakeWire();
        foreach ($cursors as $cursor) {
            $wire->responseQueue[] = ['status' => 200, 'body' => P::json(['items' => [], 'nextCursor' => $cursor]), 'headers' => []];
        }
        $client = self::client($wire);
        try {
            iterator_to_array($meter
                ? $client->meterCharges()->getMeterCharges(P::WALLET, new MeterChargesQuery(cursor: $initial))
                : $client->directRecharges()->getDirectRecharges(P::WALLET, new DirectRechargesQuery(cursor: $initial)));
            self::fail('A cursor with no progress must fail.');
        } catch (MalformedResponseException) {
            self::assertCount(count($cursors), $wire->requests);
        }
    }

    #[Test]
    #[DataProvider('resources')]
    public function it_uses_server_defaults_when_the_query_is_absent(bool $meter): void
    {
        $wire = new SignedFakeWire();
        $wire->body = P::json(self::fixture($meter));
        $client = self::client($wire);
        $items = iterator_to_array($meter ? $client->meterCharges()->getMeterCharges(P::WALLET) : $client->directRecharges()->getDirectRecharges(P::WALLET), false);
        self::assertCount(1, $items);
        self::assertCount(1, $wire->requests);
        self::assertSame('', $wire->requests[0]->getUri()->getQuery());
    }

    #[Test]
    #[DataProvider('resources')]
    public function it_surfaces_unsigned_validation_for_a_window_exceeding_thirty_one_days(bool $meter): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 422;
        $wire->body = '{"status":422,"code":"validation_failed"}';
        $client = self::client($wire);
        $from = new \DateTimeImmutable('2026-09-01T00:00:00Z');
        $to = new \DateTimeImmutable('2026-10-20T00:00:00Z');
        try {
            if ($meter) {
                $client->meterCharges()->getMeterChargesPage(P::WALLET, new MeterChargesQuery(from: $from, to: $to));
            } else {
                $client->directRecharges()->getDirectRechargesPage(P::WALLET, new DirectRechargesQuery(from: $from, to: $to));
            }
            self::fail('The unsigned validation error must surface.');
        } catch (ValidationFailedException $error) {
            self::assertSame(422, $error->status);
            self::assertSame('validation_failed', $error->rawCode);
            self::assertCount(1, $wire->requests);
            self::assertSame('from=2026-09-01T00%3A00%3A00Z&to=2026-10-20T00%3A00%3A00Z', $wire->requests[0]->getUri()->getQuery());
        }
    }

    #[Test]
    public function it_transmits_an_empty_meter_filter_for_server_validation(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 422;
        $wire->body = '{"status":422,"code":"validation_failed"}';
        try {
            self::client($wire)->meterCharges()->getMeterChargesPage(P::WALLET, new MeterChargesQuery(meterNumber: ''));
            self::fail('The invalid filter must not silently disappear.');
        } catch (ValidationFailedException) {
            self::assertSame('meterNumber=', $wire->requests[0]->getUri()->getQuery());
            self::assertCount(1, $wire->requests);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedPages(): iterable
    {
        foreach (['meterPackages', 'meterCharges', 'products', 'locations', 'recharges', 'webhooks'] as $resource) {
            foreach (['{}', '{"items":[]}', '{"nextCursor":null}', '{"items":null,"nextCursor":null}'] as $body) {
                yield "$resource $body" => [$resource, $body];
            }
        }
    }

    #[Test]
    #[DataProvider('malformedPages')]
    public function it_requires_collection_members_on_all_new_product_pages(string $resource, string $body): void
    {
        $wire = new SignedFakeWire();
        $wire->body = $body;
        $client = self::client($wire);
        $this->expectException(MalformedResponseException::class);
        match ($resource) {
            'meterPackages' => $client->meterCharges()->getMeterPackagesPage(P::WALLET),
            'meterCharges' => $client->meterCharges()->getMeterChargesPage(P::WALLET),
            'products' => $client->directRechargeProducts()->getDirectRechargeProductsPage(P::WALLET),
            'locations' => $client->directRechargeProducts()->getDirectRechargeProductLocationsPage(P::WALLET, P::PRODUCT),
            'recharges' => $client->directRecharges()->getDirectRechargesPage(P::WALLET),
            'webhooks' => $client->webhookEvents()->getWebhookEventsPage(),
            default => throw new \LogicException('Unknown test resource.'),
        };
    }
}
