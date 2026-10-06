<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\CatalogueCard;
use Anis\Partners\Models\CatalogueCategory;
use Anis\Partners\Models\CatalogueSubcategory;
use Anis\Partners\Models\Page;

/** Reads categories, subcategories, and wallet-priced catalogue cards. */
final class CatalogueOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Walks every category page for the selected wallet. @return \Generator<int, CatalogueCategory> */
    public function listCategories(string $walletId): \Generator
    {
        $cursor = null;
        $seenCursors = [];
        do {
            $page = $this->listCategoriesPage($walletId, $cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $this->ensureCursorProgress($page->nextCursor, $seenCursors);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /** Reads one category page with the caller's cursor. */
    /** @return Page<CatalogueCategory> */
    public function listCategoriesPage(string $walletId, ?string $cursor = null): Page
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/catalog/categories';
        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/catalog/categories', $this->cursor($path, $cursor), [CatalogueCategory::class, 'fromArray']);
    }

    /** Walks every subcategory page for the selected category. @return \Generator<int, CatalogueSubcategory> */
    public function listSubcategories(string $walletId, string $categoryId): \Generator
    {
        $cursor = null;
        $seenCursors = [];
        do {
            $page = $this->listSubcategoriesPage($walletId, $categoryId, $cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $this->ensureCursorProgress($page->nextCursor, $seenCursors);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /** Reads one subcategory page with the caller's cursor. */
    /** @return Page<CatalogueSubcategory> */
    public function listSubcategoriesPage(string $walletId, string $categoryId, ?string $cursor = null): Page
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/catalog/categories/' . Uuid::canonical($categoryId, 'category id') . '/subcategories';
        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories', $this->cursor($path, $cursor), [CatalogueSubcategory::class, 'fromArray']);
    }

    /** Reads one subcategory detail. */
    public function getSubcategory(string $walletId, string $subcategoryId): CatalogueSubcategory
    {
        $id = Uuid::canonical($subcategoryId, 'subcategory id');
        /** @var CatalogueSubcategory */
        return $this->fetchModel($this->transport, '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}', 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/catalog/subcategories/' . $id, [CatalogueSubcategory::class, 'fromArray']);
    }

    /** Walks all card pages, returning the price shown for this wallet. @return \Generator<int, CatalogueCard> */
    public function listCards(string $walletId, string $subcategoryId): \Generator
    {
        $cursor = null;
        $seenCursors = [];
        do {
            $page = $this->listCardsPage($walletId, $subcategoryId, $cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $this->ensureCursorProgress($page->nextCursor, $seenCursors);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /** Reads one wallet-priced card page with the caller's cursor. */
    /** @return Page<CatalogueCard> */
    public function listCardsPage(string $walletId, string $subcategoryId, ?string $cursor = null): Page
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/catalog/subcategories/' . Uuid::canonical($subcategoryId, 'subcategory id') . '/cards';
        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards', $this->cursor($path, $cursor), [CatalogueCard::class, 'fromArray']);
    }
}
