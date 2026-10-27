<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\AnisPartnersInvalidArgumentException;
use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\DirectRechargeAmountIn;
use Anis\Partners\Models\DirectRechargeLocation;
use Anis\Partners\Models\DirectRechargeLocationsQuery;
use Anis\Partners\Models\DirectRechargeProduct;
use Anis\Partners\Models\DirectRechargeProductsQuery;
use Anis\Partners\Models\DirectRechargeProductSummary;
use Anis\Partners\Models\DirectRechargeQuote;
use Anis\Partners\Models\Page;

/** Reads the direct recharge products a wallet can use, their places and what an amount would cost. */
final class DirectRechargeProductOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /**
     * Reads one page of the products priced for this wallet.
     * @return Page<DirectRechargeProductSummary>
     */
    public function getDirectRechargeProductsPage(string $walletId, ?DirectRechargeProductsQuery $query = null): Page
    {
        $query ??= new DirectRechargeProductsQuery();
        $path = $this->withQuery('v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/direct-recharge-products', ['type' => $query->type?->value, 'cursor' => $query->cursor]);

        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/direct-recharge-products', $path, [DirectRechargeProductSummary::class, 'fromArray'], true);
    }

    /**
     * Walks every product page; the cursor in the query only sets where the walk starts.
     * @return \Generator<int, DirectRechargeProductSummary>
     */
    public function getDirectRechargeProducts(string $walletId, ?DirectRechargeProductsQuery $query = null): \Generator
    {
        $query ??= new DirectRechargeProductsQuery();

        return $this->walk(fn(?string $cursor): Page => $this->getDirectRechargeProductsPage($walletId, new DirectRechargeProductsQuery($query->type, $cursor)), $query->cursor);
    }

    /** Reads one product with the details its recipient must give. */
    public function get(string $walletId, string $productId): DirectRechargeProduct
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/direct-recharge-products/' . Uuid::canonical($productId, 'product id');
        /** @var DirectRechargeProduct */
        return $this->fetchModel($this->transport, '/v1/wallets/{walletId}/direct-recharge-products/{productId}', $path, [DirectRechargeProduct::class, 'fromArray']);
    }

    /**
     * Reads one page of the places a list field can take, optionally narrowed by a name search.
     * @return Page<DirectRechargeLocation>
     */
    public function getDirectRechargeProductLocationsPage(string $walletId, string $productId, ?DirectRechargeLocationsQuery $query = null): Page
    {
        $query ??= new DirectRechargeLocationsQuery();
        $path = $this->withQuery(
            'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/direct-recharge-products/' . Uuid::canonical($productId, 'product id') . '/locations',
            ['search' => $query->search, 'cursor' => $query->cursor],
        );

        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/direct-recharge-products/{productId}/locations', $path, [DirectRechargeLocation::class, 'fromArray'], true);
    }

    /**
     * Walks every location page; the cursor in the query only sets where the walk starts.
     * @return \Generator<int, DirectRechargeLocation>
     */
    public function getDirectRechargeProductLocations(string $walletId, string $productId, ?DirectRechargeLocationsQuery $query = null): \Generator
    {
        $query ??= new DirectRechargeLocationsQuery();

        return $this->walk(fn(?string $cursor): Page => $this->getDirectRechargeProductLocationsPage($walletId, $productId, new DirectRechargeLocationsQuery($query->search, $cursor)), $query->cursor);
    }

    /**
     * Prices an amount without reserving anything; the amount is dinars or the product currency per $amountIn.
     *
     * @param string $amount A decimal string with three places.
     */
    public function quote(string $walletId, string $productId, string $amount, DirectRechargeAmountIn $amountIn): DirectRechargeQuote
    {
        if (preg_match('/\A\d+\.\d{3}\z/D', $amount) !== 1) {
            throw new AnisPartnersInvalidArgumentException('The quote amount must be a decimal string with three places.');
        }
        $path = $this->withQuery(
            'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/direct-recharge-products/' . Uuid::canonical($productId, 'product id') . '/quote',
            ['amount' => $amount, 'amountIn' => $amountIn->value],
        );
        /** @var DirectRechargeQuote $quote */
        $quote = $this->fetchModel($this->transport, '/v1/wallets/{walletId}/direct-recharge-products/{productId}/quote', $path, [DirectRechargeQuote::class, 'fromArray']);
        if ($quote->productId !== Uuid::canonical($productId, 'product id')) {
            throw new MalformedResponseException('The quote names another product than the one asked for.');
        }

        return $quote;
    }
}
