<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Verification;

use Anis\Partners\Tests\Support\ArrayCache;
use Anis\Partners\Tests\Support\FakeHttpClient;
use Anis\Partners\Tests\Support\FrozenClock;
use Anis\Partners\Tests\Support\RecordingLogger;
use Anis\Partners\Tests\Support\ThrowingCache;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\SigningKeysUnavailableException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpSigningKeySourceTest extends TestCase
{
    #[Test]
    public function it_caches_in_memory_expires_refreshes_and_reuses_the_shared_cache(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $factory = new HttpFactory();
        $client = new FakeHttpClient();
        $cache = new ArrayCache();
        $source = new HttpSigningKeySource($client, $factory, 'https://partners.anis.ly', 600, $cache, $clock);

        $source->get();
        self::assertSame('identity', $client->lastRequest?->getHeaderLine('Accept-Encoding'));
        $source->get();
        self::assertSame(1, $client->calls);
        $clock->advance(601);
        $source->get();
        self::assertSame(2, $client->calls);
        $source->refresh();
        self::assertSame(3, $client->calls);

        $secondClient = new FakeHttpClient();
        $secondSource = new HttpSigningKeySource($secondClient, $factory, 'https://partners.anis.ly', 600, $cache, $clock);
        $secondSource->get();
        self::assertSame(0, $secondClient->calls);
    }

    #[Test]
    public function it_uses_the_fetched_document_when_shared_cache_reads_and_writes_throw(): void
    {
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: new ThrowingCache());

        self::assertSame([], $source->get()->keys);
        self::assertSame(1, $client->calls);
    }

    #[Test]
    public function it_logs_a_cache_write_warning_without_reusing_the_unknown_key_event_id(): void
    {
        $logger = new RecordingLogger();
        $source = new HttpSigningKeySource(new FakeHttpClient(), new HttpFactory(), 'https://partners.anis.ly', cache: new ThrowingCache(), logger: $logger);
        $source->get();

        $warnings = array_values(array_filter($logger->records, static fn(array $record): bool => $record['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertArrayNotHasKey('event_id', $warnings[0]['context']);
        self::assertSame('cache_write_failed', $warnings[0]['context']['reason']);
    }

    #[Test]
    public function it_refetches_a_shared_cache_document_stamped_too_far_in_the_future(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $cache = new ArrayCache();
        $authority = 'https://partners.anis.ly/';
        $key = 'anis_partners_keys_' . substr(hash('sha256', rtrim(strtolower($authority), '/')), 0, 32);
        $cache->set($key, json_encode(['document' => '{"keys":[]}', 'fetchedAt' => $clock->now()->getTimestamp() + 61], JSON_THROW_ON_ERROR));
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), $authority, 600, $cache, $clock);

        self::assertSame([], $source->get()->keys);
        self::assertSame(1, $client->calls);
    }

    #[Test]
    public function it_refuses_redirected_or_encoded_signing_key_documents(): void
    {
        foreach ([[302, []], [200, ['Content-Encoding' => 'gzip']]] as [$status, $headers]) {
            $client = new FakeHttpClient('{"keys":[]}', $status);
            $client->headers = $headers;
            $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly');

            try {
                $source->get();
                self::fail('A redirect or encoded key document must be refused.');
            } catch (SigningKeysUnavailableException) {
                self::assertSame(1, $client->calls);
            }
        }
    }

    #[Test]
    public function it_keeps_shared_key_cache_entries_separate_by_authority(): void
    {
        $cache = new ArrayCache();
        $factory = new HttpFactory();
        $first = new FakeHttpClient('{"keys":[]}');
        $second = new FakeHttpClient('{"keys":[]}');
        (new HttpSigningKeySource($first, $factory, 'https://one.anis.ly', cache: $cache))->get();
        (new HttpSigningKeySource($second, $factory, 'https://two.anis.ly', cache: $cache))->get();

        self::assertSame(1, $first->calls);
        self::assertSame(1, $second->calls);
    }

    #[Test]
    public function it_normalizes_scheme_host_and_default_port_for_the_shared_cache_key(): void
    {
        $cache = new ArrayCache();
        $source = new HttpSigningKeySource(new FakeHttpClient(), new HttpFactory(), 'https://PARTNERS.example:443/', cache: $cache);
        $source->get();

        self::assertTrue($cache->has('anis_partners_keys_63314f03abd62f39665763f229f6d285'));
    }

    #[Test]
    public function it_treats_a_garbage_shared_cache_entry_as_a_miss(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $cache = new ArrayCache();
        $key = 'anis_partners_keys_' . substr(hash('sha256', 'https://partners.anis.ly'), 0, 32);
        $cache->set($key, 'not-json');
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', 600, $cache, $clock);

        self::assertSame([], $source->get()->keys);
        self::assertSame(1, $client->calls);
    }

    #[Test]
    public function it_wraps_a_key_document_fetch_failure_and_preserves_its_cause(): void
    {
        $client = new FakeHttpClient();
        $cause = new \RuntimeException('host transport failed');
        $client->failure = $cause;
        $factory = new HttpFactory();
        $source = new HttpSigningKeySource($client, $factory, 'https://partners.anis.ly');

        try {
            $source->get();
            self::fail('The failed key fetch should raise an SDK exception.');
        } catch (SigningKeysUnavailableException $exception) {
            self::assertSame($cause, $exception->getPrevious());
            self::assertInstanceOf(\Anis\Partners\AnisPartnersException::class, $exception);
        }
    }
}
