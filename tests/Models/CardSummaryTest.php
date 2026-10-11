<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\CatalogueCard;
use Anis\Partners\Models\CatalogueSubcategory;
use Anis\Partners\Models\MaskedCard;
use Anis\Partners\Models\Order;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CardSummaryTest extends TestCase
{
    private const ID = '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046';

    #[Test]
    public function it_shares_full_card_details_between_masked_reveal_and_order_credentials(): void
    {
        $summary = ['id' => self::ID, 'name' => ['ar' => 'بطاقة', 'en' => 'Card'], 'faceValue' => '10 USD', 'logo' => 'https://assets.anis.ly/card.png',
            'subcategory' => ['id' => self::ID, 'name' => ['en' => 'Games']], 'category' => ['id' => self::ID, 'name' => ['en' => 'Digital']]];
        $sale = ['soldCardId' => self::ID, 'operationId' => self::ID, 'invoiceId' => self::ID, 'invoiceNumber' => 1042, 'card' => $summary,
            'serialNumberMasked' => '****1234', 'credentialAvailable' => true, 'purchasedAt' => '2026-10-11T09:00:00Z',
            'unitPrice' => ['amount' => '10.500', 'currency' => 'LYD'], 'expiryDate' => '2027-03-31'];
        $masked = MaskedCard::fromArray($sale);
        self::assertSame($sale, $masked->jsonSerialize());
        self::assertSame(self::ID, $masked->soldCardId);
        self::assertSame(self::ID, $masked->operationId);
        $reveal = ['soldCardId' => self::ID, 'serialNumber' => 'serial', 'voucher' => 'voucher', 'revealedAt' => '2026-10-11T09:01:00Z',
            'expiryDate' => '2027-03-31', 'invoiceId' => self::ID, 'card' => $summary, 'purchasedAt' => '2026-10-11T09:00:00Z'];
        self::assertSame($reveal, RevealedCredential::fromArray($reveal)->jsonSerialize());
        $order = Order::fromArray(['operationId' => self::ID, 'soldCards' => [$reveal]]);
        self::assertNotNull($order->soldCards);
        self::assertArrayHasKey(0, $order->soldCards);
        self::assertSame($reveal, $order->soldCards[0]->jsonSerialize());
    }

    #[Test]
    public function it_reads_catalogue_metadata_and_omits_empty_display_values(): void
    {
        $card = CatalogueCard::fromArray(['id' => self::ID, 'subcategoryId' => self::ID, 'logo' => 'https://assets.anis.ly/card.png',
            'category' => ['id' => self::ID, 'name' => ['ar' => '', 'en' => 'Digital']], 'name' => ['ar' => '', 'en' => ''], 'faceValue' => '',
            'minimumQuantity' => 1, 'maximumQuantity' => 100]);
        self::assertSame('Digital', $card->category?->name?->en);
        $wire = $card->jsonSerialize();
        self::assertArrayNotHasKey('faceValue', $wire);
        self::assertArrayNotHasKey('name', $wire);
        self::assertSame(['id' => self::ID, 'name' => ['en' => 'Digital']], $wire['category']);
        self::assertSame('https://assets.anis.ly/card.png', $wire['logo']);
        self::assertSame('Description', CatalogueSubcategory::fromArray(['id' => self::ID, 'description' => ['en' => 'Description']])->description?->en);
        $wallet = Wallet::fromArray(['id' => self::ID, 'balance' => ['amount' => '21.000', 'currency' => 'LYD']]);
        self::assertArrayNotHasKey('currency', $wallet->jsonSerialize());
        self::assertSame('LYD', $wallet->balance?->currency);
    }
}
