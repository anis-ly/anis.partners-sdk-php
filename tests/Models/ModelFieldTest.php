<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Models;

use Anis\Partners\Models\CatalogueCard;
use Anis\Partners\Models\CatalogueCategoryType;
use Anis\Partners\Models\CatalogueSubcategory;
use Anis\Partners\Models\MaskedCard;
use Anis\Partners\Models\Order;
use Anis\Partners\Models\OrderStatus;
use Anis\Partners\Models\RevealedCredential;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelFieldTest extends TestCase
{
    #[Test]
    public function it_reads_a_subcategory_disclaimer(): void
    {
        $subcategory = CatalogueSubcategory::fromArray([
            'id' => '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c',
            'disclaimer' => ['ar' => 'ملاحظة', 'en' => 'Valid in Libya only.'],
        ]);

        self::assertSame('Valid in Libya only.', $subcategory->disclaimer?->en);
        self::assertSame('ملاحظة', $subcategory->disclaimer->ar);
    }

    #[Test]
    public function it_leaves_an_absent_subcategory_disclaimer_null(): void
    {
        self::assertNull(CatalogueSubcategory::fromArray(['id' => '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c'])->disclaimer);
    }

    #[Test]
    public function it_reads_catalogue_card_quantity_limits(): void
    {
        $card = CatalogueCard::fromArray([
            'id' => '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48',
            'subcategoryId' => '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c',
            'minimumQuantity' => 2,
            'maximumQuantity' => 50,
        ]);

        self::assertSame(2, $card->minimumQuantity);
        self::assertSame(50, $card->maximumQuantity);
    }

    #[Test]
    public function it_leaves_absent_catalogue_card_quantity_limits_null(): void
    {
        $card = CatalogueCard::fromArray([
            'id' => '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48',
            'subcategoryId' => '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c',
        ]);

        self::assertNull($card->minimumQuantity);
        self::assertNull($card->maximumQuantity);
    }

    #[Test]
    public function it_reads_order_reference_failure_code_and_withheld_flag(): void
    {
        $order = Order::fromArray([
            'operationId' => '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
            'status' => 'failed',
            'externalReference' => 'INV-77',
            'failureCode' => 'out_of_stock',
            'codesWithheld' => true,
        ]);

        self::assertSame('INV-77', $order->externalReference);
        self::assertSame('out_of_stock', $order->failureCode);
        self::assertTrue($order->codesWithheld);
    }

    #[Test]
    public function it_leaves_new_order_members_null_when_absent(): void
    {
        $order = Order::fromArray([
            'operationId' => '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
            'status' => 'completed',
        ]);

        self::assertNull($order->externalReference);
        self::assertNull($order->failureCode);
        self::assertNull($order->codesWithheld);
    }

    #[Test]
    public function it_reads_revealed_credential_expiry_and_reveal_details(): void
    {
        $credential = RevealedCredential::fromArray([
            'soldCardId' => '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
            'voucher' => '1234',
            'expiryDate' => '2027-03-31',
            'invoiceId' => 'c1a7e2d9-5b64-4f18-9e03-2d7a6c4b8f51',
            'card' => ['id' => '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 'name' => ['ar' => 'بطاقة', 'en' => 'Card']],
            'purchasedAt' => '2026-09-19T08:00:00Z',
        ]);

        self::assertSame('2027-03-31', $credential->expiryDate);
        self::assertSame('c1a7e2d9-5b64-4f18-9e03-2d7a6c4b8f51', $credential->invoiceId);
        self::assertSame('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', $credential->card?->id);
        self::assertNotNull($credential->card);
        self::assertSame('Card', $credential->card->name?->en);
        self::assertSame('2026-09-19T08:00:00+00:00', $credential->purchasedAt?->format(DATE_ATOM));
    }

    #[Test]
    public function it_leaves_new_revealed_credential_members_null_when_absent(): void
    {
        $credential = RevealedCredential::fromArray([
            'soldCardId' => '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
            'voucher' => '1234',
        ]);

        self::assertNull($credential->expiryDate);
        self::assertNull($credential->invoiceId);
        self::assertNull($credential->card);
        self::assertNull($credential->purchasedAt);
    }

    #[Test]
    public function it_reads_masked_card_price_expiry_invoice_and_catalogue_details(): void
    {
        $card = MaskedCard::fromArray([
            'id' => '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
            'credentialAvailable' => false,
            'unitPrice' => ['amount' => '10.500', 'currency' => 'LYD'],
            'expiryDate' => '2027-03-31',
            'invoiceNumber' => 1042,
            'faceValue' => '10 USD',
            'subcategory' => ['id' => '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c', 'name' => ['ar' => 'فئة', 'en' => 'Games']],
        ]);

        self::assertSame('10.500', $card->unitPrice?->amount());
        self::assertSame('2027-03-31', $card->expiryDate);
        self::assertSame(1042, $card->invoiceNumber);
        self::assertSame('10 USD', $card->faceValue);
        self::assertSame('7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c', $card->subcategory?->id);
        self::assertNotNull($card->subcategory);
        self::assertSame('Games', $card->subcategory->name?->en);
        self::assertFalse($card->credentialAvailable);
    }

    #[Test]
    public function it_leaves_new_masked_card_members_null_when_absent(): void
    {
        $card = MaskedCard::fromArray([
            'id' => '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
            'credentialAvailable' => true,
        ]);

        self::assertNull($card->unitPrice);
        self::assertNull($card->expiryDate);
        self::assertNull($card->invoiceNumber);
        self::assertNull($card->faceValue);
        self::assertNull($card->subcategory);
    }

    #[Test]
    public function it_maps_future_enum_strings_to_unknown(): void
    {
        self::assertSame(OrderStatus::Unknown, Order::fromArray([
            'operationId' => '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34',
            'status' => 'waitingForOwner',
        ])->status);
        self::assertSame(CatalogueCategoryType::Unknown, CatalogueCategoryType::parse('regional'));
    }
}
