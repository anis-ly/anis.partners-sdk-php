<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\Page;
use Anis\Partners\Models\Wallet;

/** Reads wallets granted to the application. */
final class WalletOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Walks all pages so partners can process the complete granted-wallet list. @return \Generator<int, Wallet> */
    public function list(): \Generator
    {
        $cursor = null;
        $seenCursors = [];
        do {
            $page = $this->listPage($cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $this->ensureCursorProgress($page->nextCursor, $seenCursors);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /** Reads one page for applications that manage their own continuation cursor. */
    /** @return Page<Wallet> */
    public function listPage(?string $cursor = null): Page
    {
        return $this->fetchPage($this->transport, '/v1/wallets', $this->cursor('v1/wallets', $cursor), [Wallet::class, 'fromArray']);
    }

    /** Reads one granted wallet by canonical identifier. */
    public function get(string $walletId): Wallet
    {
        $id = Uuid::canonical($walletId, 'wallet id');
        /** @var Wallet */
        return $this->fetchModel($this->transport, '/v1/wallets/{walletId}', 'v1/wallets/' . $id, [Wallet::class, 'fromArray']);
    }
}
