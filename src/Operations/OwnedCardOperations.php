<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\MaskedCard;
use Anis\Partners\Models\Page;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\RevealedCredentialCollection;
use Anis\Partners\Signing\SignatureProfile;

/** Reads owned cards and requests credentials only through explicit reveal routes. */
final class OwnedCardOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Walks all owned-card pages in a wallet. @return \Generator<int, MaskedCard> */
    public function list(string $walletId): \Generator
    {
        $cursor = null;
        $seenCursors = [];
        do {
            $page = $this->listPage($walletId, $cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $this->ensureCursorProgress($page->nextCursor, $seenCursors);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /** Reads one owned-card page with the caller's cursor. */
    /** @return Page<MaskedCard> */
    public function listPage(string $walletId, ?string $cursor = null): Page
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/cards';
        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/cards', $this->cursor($path, $cursor), [MaskedCard::class, 'fromArray']);
    }

    /** Reads one card with its credential masked. */
    public function get(string $walletId, string $soldCardId): MaskedCard
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/cards/' . Uuid::canonical($soldCardId, 'sold card id');
        /** @var MaskedCard */
        return $this->fetchModel($this->transport, '/v1/wallets/{walletId}/cards/{soldCardId}', $path, [MaskedCard::class, 'fromArray']);
    }

    /** Reveals one credential using a signed bodyless mutation. */
    public function reveal(string $walletId, string $soldCardId): RevealedCredential
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/cards/' . Uuid::canonical($soldCardId, 'sold card id') . '/reveal';
        $response = $this->transport->request('POST', '/v1/wallets/{walletId}/cards/{soldCardId}/reveal', $path, SignatureProfile::BodylessNonceMutation);
        try {
            return RevealedCredential::fromArray($response->json);
        } catch (\Throwable) {
            throw new \Anis\Partners\Errors\MalformedResponseException('The verified reveal answer does not match the credential model.');
        }
    }

    /** Reveals all invoice credentials atomically through a signed bodyless mutation. */
    public function revealInvoice(string $walletId, string $invoiceId): RevealedCredentialCollection
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId, 'wallet id') . '/invoices/' . Uuid::canonical($invoiceId, 'invoice id') . '/cards/reveal';
        $response = $this->transport->request('POST', '/v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal', $path, SignatureProfile::BodylessNonceMutation);
        try {
            return RevealedCredentialCollection::fromArray($response->json);
        } catch (\Throwable) {
            throw new \Anis\Partners\Errors\MalformedResponseException('The verified reveal answer does not match the credential collection model.');
        }
    }
}
