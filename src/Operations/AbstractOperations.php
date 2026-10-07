<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\MalformedResponseException;
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

        try {
            return $hydrate($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The Anis response does not match the requested model.');
        }
    }

    /**
     * @template T of object
     * @param callable(array<array-key, mixed>): T $hydrate
     * @return Page<T>
     */
    protected function fetchPage(PartnerTransport $transport, string $template, string $path, callable $hydrate): Page
    {
        $response = $transport->request('GET', $template, $path, SignatureProfile::SafeRead);
        try {
            $page = Page::fromArray($response->json);
        } catch (\Throwable) {
            throw new MalformedResponseException('The Anis response does not match the requested page.');
        }
        $items = [];
        foreach ($page->items as $item) {
            if (!is_array($item)) {
                throw new MalformedResponseException('The Anis page contains an invalid item.');
            }
            try {
                $items[] = $hydrate($item);
            } catch (\Throwable) {
                throw new MalformedResponseException('The Anis page item does not match the requested model.');
            }
        }

        return new Page($items, $page->nextCursor);
    }

    /** Stops a repeated cursor from issuing signed reads forever. */
    /** @param array<string, true> $seenCursors */
    protected function ensureCursorProgress(?string $nextCursor, array &$seenCursors): void
    {
        if ($nextCursor !== null && $nextCursor !== '') {
            if (isset($seenCursors[$nextCursor])) {
                throw new \Anis\Partners\Errors\MalformedResponseException('Anis repeated a paging cursor.');
            }
            $seenCursors[$nextCursor] = true;
        }
    }

    /** Encodes an opaque paging cursor once so the transmitted query is signed verbatim. */
    protected function cursor(string $path, ?string $cursor): string
    {
        return $cursor === null || $cursor === '' ? $path : $path . '?cursor=' . rawurlencode($cursor);
    }
}
