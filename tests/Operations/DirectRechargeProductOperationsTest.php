<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\AnisPartnersInvalidArgumentException;
use Anis\Partners\Models\DirectRechargeAmountIn;
use Anis\Partners\Models\DirectRechargeFieldFor;
use Anis\Partners\Models\DirectRechargeFieldKind;
use Anis\Partners\Models\DirectRechargeLocationsQuery;
use Anis\Partners\Models\DirectRechargeProductsQuery;
use Anis\Partners\Models\DirectRechargeProductType;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectRechargeProductOperationsTest extends TestCase
{
    private static function wire(string $body): SignedFakeWire
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->body = $body;

        return $wire;
    }

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    /** @return array<string, mixed> */
    private static function product(): array
    {
        return [
            'id' => P::PRODUCT,
            'title' => 'فودافون كاش مصر',
            'type' => 'external',
            'payoutCurrency' => ['name' => 'جنيه مصري'],
            'available' => true,
            'limits' => ['minimumTotal' => ['amount' => '10.000', 'currency' => 'LYD'], 'maximumTotal' => ['amount' => '5000.000', 'currency' => 'LYD'], 'minimumPayout' => '50.000', 'maximumPayout' => '30000.000'],
        ];
    }

    #[Test]
    public function it_lists_products_by_type_without_signing_key_fetches(): void
    {
        $wire = self::wire(P::json(['items' => [self::product(), ['id' => P::PRODUCT, 'title' => 'x', 'type' => 'billing', 'payoutCurrency' => ['name' => 'c'], 'available' => false]], 'nextCursor' => null]));
        $page = self::client($wire)->directRechargeProducts()->getDirectRechargeProductsPage(P::WALLET, new DirectRechargeProductsQuery(DirectRechargeProductType::External, 'c+1'));

        self::assertSame('type=external&cursor=c%2B1', $wire->requests[0]->getUri()->getQuery());
        self::assertCount(1, $wire->requests);
        self::assertTrue($page->items[0]->available);
        self::assertSame('50.000', $page->items[0]->limits?->minimumPayout);
        self::assertSame('جنيه مصري', $page->items[0]->payoutCurrency->name);
        self::assertFalse($page->items[1]->available);
        self::assertNull($page->items[1]->limits);
        self::assertSame(DirectRechargeProductType::Billing, $page->items[1]->type);
    }

    #[Test]
    public function it_reads_a_product_with_its_recipient_fields_including_the_wire_for_member(): void
    {
        $data = self::product() + ['recipientFields' => [
            ['key' => 'recipient_number', 'name' => ['ar' => 'رقم', 'en' => 'Number'], 'kind' => 'text', 'for' => 'recipient', 'order' => 1, 'validation' => '^01[0-9]{9}$', 'validationMessage' => ['ar' => 'غير صحيح', 'en' => 'Invalid number']],
            ['key' => 'Sender', 'name' => ['en' => 'Sender'], 'kind' => 'list', 'for' => 'sender', 'order' => 2],
            ['key' => 'Hint', 'name' => ['en' => 'Hint'], 'kind' => 'surprise', 'for' => 'elsewhere', 'order' => 3],
        ]];
        $product = self::client(self::wire(P::json($data)))->directRechargeProducts()->get(P::WALLET, P::PRODUCT);

        self::assertSame(DirectRechargeFieldFor::Recipient, $product->recipientFields[0]->for);
        self::assertSame(DirectRechargeFieldKind::List, $product->recipientFields[1]->kind);
        self::assertNull($product->recipientFields[1]->validation);
        self::assertSame(DirectRechargeFieldKind::Unknown, $product->recipientFields[2]->kind);
        self::assertSame('recipient', $product->recipientFields[0]->jsonSerialize()['for']);
        self::assertSame('Invalid number', $product->recipientFields[0]->validationMessage?->en);
    }

    #[Test]
    public function it_searches_locations_with_arabic_text_encoded_once_and_follows_cursors(): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->responseQueue = [
            ['status' => 200, 'body' => P::json(['items' => [['id' => '1a2b3c4d-1111-4222-8333-444455556666', 'name' => 'القاهرة']], 'nextCursor' => 'n=1']), 'headers' => []],
            ['status' => 200, 'body' => P::json(['items' => [['id' => '2a2b3c4d-1111-4222-8333-444455556666', 'name' => 'الإسكندرية']], 'nextCursor' => null]), 'headers' => []],
        ];
        $names = [];
        foreach (self::client($wire)->directRechargeProducts()->getDirectRechargeProductLocations(P::WALLET, P::PRODUCT, new DirectRechargeLocationsQuery('طرابلس')) as $location) {
            $names[] = $location->name;
        }

        self::assertSame(['القاهرة', 'الإسكندرية'], $names);
        self::assertSame('search=' . rawurlencode('طرابلس'), $wire->requests[0]->getUri()->getQuery());
        self::assertSame('search=' . rawurlencode('طرابلس') . '&cursor=n%3D1', $wire->requests[1]->getUri()->getQuery());
    }

    #[Test]
    public function it_quotes_in_dinars_and_in_the_product_currency_keeping_strings_exact(): void
    {
        $body = P::json(['productId' => P::PRODUCT, 'payout' => ['amount' => '1500.000', 'currency' => ['name' => 'جنيه مصري']], 'exchangeRate' => '0.155000', 'price' => ['amount' => '232.500', 'currency' => 'LYD'], 'commission' => ['amount' => '5.000', 'currency' => 'LYD'], 'total' => ['amount' => '237.500', 'currency' => 'LYD']]);
        foreach ([DirectRechargeAmountIn::Lyd, DirectRechargeAmountIn::Fx] as $in) {
            $wire = self::wire($body);
            $quote = self::client($wire)->directRechargeProducts()->quote(P::WALLET, P::PRODUCT, '1500.000', $in);
            self::assertSame('amount=1500.000&amountIn=' . $in->value, $wire->requests[0]->getUri()->getQuery());
            self::assertSame('0.155000', $quote->exchangeRate);
            self::assertSame('1500.000', $quote->payout->amount);
            self::assertSame('جنيه مصري', $quote->payout->currency->name);
            self::assertSame('237.500', $quote->total->amount());
        }
    }

    #[Test]
    public function it_refuses_a_malformed_quote_amount_before_sending(): void
    {
        $wire = self::wire('{}');
        $this->expectException(AnisPartnersInvalidArgumentException::class);
        try {
            self::client($wire)->directRechargeProducts()->quote(P::WALLET, P::PRODUCT, '1500', DirectRechargeAmountIn::Fx);
        } finally {
            self::assertSame([], $wire->requests);
        }
    }

    #[Test]
    public function it_refuses_a_quote_for_another_product(): void
    {
        $body = P::json(['productId' => '00000000-0000-4000-8000-000000000001', 'payout' => ['amount' => '1.000', 'currency' => ['name' => 'c']], 'exchangeRate' => '1.000000', 'price' => ['amount' => '1.000', 'currency' => 'LYD'], 'commission' => ['amount' => '0.000', 'currency' => 'LYD'], 'total' => ['amount' => '1.000', 'currency' => 'LYD']]);
        $this->expectException(\Anis\Partners\Errors\MalformedResponseException::class);
        self::client(self::wire($body))->directRechargeProducts()->quote(P::WALLET, P::PRODUCT, '1.000', DirectRechargeAmountIn::Lyd);
    }
}
