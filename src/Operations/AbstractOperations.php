<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\MalformedResponseException;
use Anis\Partners\Models\ModelData;
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
    protected function fetchPage(PartnerTransport $transport, string $template, string $path, callable $hydrate, bool $strictEnvelope = false): Page
    {
        $response = $transport->request('GET', $template, $path, SignatureProfile::SafeRead);
        try {
            if ($strictEnvelope) {
                ModelData::requireMembers($response->json, 'items', 'nextCursor');
                ModelData::requiredObjectList($response->json, 'items');
            }
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

    /**
     * Appends the present query members once, percent-encoded per RFC 3986, so the transmitted query is signed verbatim.
     * @param array<string, string|int|null> $params
     */
    protected function withQuery(string $path, array $params): string
    {
        $present = array_filter($params, static fn(string|int|null $value): bool => $value !== null);

        return $present === [] ? $path : $path . '?' . http_build_query($present, '', '&', PHP_QUERY_RFC3986);
    }

    /** Formats a moment as the UTC timestamp the wire uses. */
    protected static function utc(?\DateTimeInterface $time): ?string
    {
        if ($time === null) {
            return null;
        }
        $utc = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format($utc->format('u') === '000000' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z');
    }

    /** Checks a page size before any signed read leaves the process. */
    protected static function pageSize(?int $pageSize): ?int
    {
        if ($pageSize !== null && ($pageSize < 1 || $pageSize > 100)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The page size must be between 1 and 100.');
        }

        return $pageSize;
    }

    /**
     * Walks every page of a list, refusing a cursor that repeats.
     * @template T
     * @param callable(?string): Page<T> $fetch
     * @return \Generator<int, T>
     */
    protected function walk(callable $fetch, ?string $initialCursor = null): \Generator
    {
        $cursor = $initialCursor;
        $seenCursors = $initialCursor === null || $initialCursor === '' ? [] : [$initialCursor => true];
        do {
            $page = $fetch($cursor);
            foreach ($page->items as $item) {
                yield $item;
            }
            $this->ensureCursorProgress($page->nextCursor, $seenCursors);
            $cursor = $page->nextCursor;
        } while ($cursor !== null && $cursor !== '');
    }
}
