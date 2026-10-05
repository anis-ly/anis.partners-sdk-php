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
        do {
            $page = $this->listPage($walletId, $cursor);
            yield from $page->items;
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /** Reads one owned-card page with the caller's cursor. */
    /** @return Page<MaskedCard> */
    public function listPage(string $walletId, ?string $cursor = null): Page
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId) . '/cards';
        return $this->fetchPage($this->transport, '/v1/wallets/{walletId}/cards', $this->cursor($path, $cursor), [MaskedCard::class, 'fromArray']);
    }

    /** Reads one card with its credential masked. */
    public function get(string $walletId, string $soldCardId): MaskedCard
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId) . '/cards/' . Uuid::canonical($soldCardId);
        /** @var MaskedCard */
        return $this->fetchModel($this->transport, '/v1/wallets/{walletId}/cards/{soldCardId}', $path, [MaskedCard::class, 'fromArray']);
    }

    /** Reveals one credential using a signed bodyless mutation. */
    public function reveal(string $walletId, string $soldCardId): RevealedCredential
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId) . '/cards/' . Uuid::canonical($soldCardId) . '/reveal';
        $response = $this->transport->request('POST', '/v1/wallets/{walletId}/cards/{soldCardId}/reveal', $path, SignatureProfile::BodylessNonceMutation);
        return RevealedCredential::fromArray($response->json);
    }

    /** Reveals all invoice credentials atomically through a signed bodyless mutation. */
    public function revealInvoice(string $walletId, string $invoiceId): RevealedCredentialCollection
    {
        $path = 'v1/wallets/' . Uuid::canonical($walletId) . '/invoices/' . Uuid::canonical($invoiceId) . '/cards/reveal';
        $response = $this->transport->request('POST', '/v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal', $path, SignatureProfile::BodylessNonceMutation);
        return RevealedCredentialCollection::fromArray($response->json);
    }
}
