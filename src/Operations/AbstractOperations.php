<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Models\Page;
use Anis\Partners\Signing\SignatureProfile;

/** Shares only payload decoding mechanics; route and signature choices stay with each operation. */
abstract class AbstractOperations
{
    /**
     * @template T of object
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return T
     */
    protected function fetchModel(PartnerTransport $transport, string $template, string $path, callable $hydrate): object
    {
        $response = $transport->request('GET', $template, $path, SignatureProfile::SafeRead);

        return $hydrate($response->json);
    }

    /**
     * @template T of object
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return Page<T>
     */
    protected function fetchPage(PartnerTransport $transport, string $template, string $path, callable $hydrate): Page
    {
        $response = $transport->request('GET', $template, $path, SignatureProfile::SafeRead);
        $page = Page::fromArray($response->json);
        $items = [];
        foreach ($page->items as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException('A page item must be an object.');
            }
            $items[] = $hydrate($item);
        }

        return new Page($items, $page->nextCursor);
    }

    /** Encodes an opaque paging cursor once so the transmitted query is signed verbatim. */
    protected function cursor(string $path, ?string $cursor): string
    {
        return $cursor === null || $cursor === '' ? $path : $path . '?cursor=' . rawurlencode($cursor);
    }
}
