<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Verification;

use Anis\Partners\Errors\AnisPartnersInvalidArgumentException;
use Anis\Partners\Tests\Support\ArrayCache;
use Anis\Partners\Tests\Support\FakeHttpClient;
use Anis\Partners\Tests\Support\FrozenClock;
use Anis\Partners\Tests\Support\RecordingLogger;
use Anis\Partners\Tests\Support\ThrowingCache;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\SigningKeysUnavailableException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpSigningKeySourceTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function cooldownCaches(): iterable
    {
        yield 'without shared cache' => [false];
        yield 'with shared cache' => [true];
    }

    /** @return iterable<string, array{bool}> */
    public static function sharedAdoptionPaths(): iterable
    {
        yield 'cached get' => [false];
        yield 'unknown key refresh' => [true];
    }

    #[Test]
    public function it_refuses_a_cache_lifetime_longer_than_five_minutes(): void
    {
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cacheSeconds: 300);
        self::assertSame([], $source->get()->keys);
        self::assertSame(1, $client->calls);

        $this->expectException(AnisPartnersInvalidArgumentException::class);
        $this->expectExceptionMessage('HttpSigningKeySource::$cacheSeconds must be at most 300 seconds.');
        new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cacheSeconds: 301);
    }

    #[Test]
    public function it_keeps_the_document_for_five_minutes_by_default(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', clock: $clock, monotonicClock: $clock->monotonicNow(...));

        $keys = $source->get();
        $clock->advance(299);
        self::assertSame($keys, $source->get());
        self::assertSame(1, $client->calls);
        $clock->advance(1);
        self::assertNotSame($keys, $source->get());
        self::assertSame(2, $client->calls);
    }

    #[Test]
    #[DataProvider('cooldownCaches')]
    public function it_does_not_extend_local_key_trust_when_the_wall_clock_moves_back(bool $sharedCache): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $sharedCache ? new ArrayCache() : null, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $keys = $source->get();
        $clock->advance(-3600);
        self::assertSame($keys, $source->get());
        self::assertSame(1, $client->calls);
        $clock->advance(299);
        self::assertSame($keys, $source->get());
        self::assertSame(1, $client->calls);
        $clock->advance(1);
        self::assertNotSame($keys, $source->get());
        self::assertSame(2, $client->calls);
    }

    #[Test]
    public function it_does_not_restore_an_expired_local_copy_from_the_same_shared_entry_after_a_small_clock_rollback(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: new ArrayCache(), clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $keys = $source->get();

        $clock->advance(-1);
        $clock->advance(300);
        self::assertNotSame($keys, $source->get());
        self::assertSame(2, $client->calls);
    }

    #[Test]
    #[DataProvider('sharedAdoptionPaths')]
    public function it_adopts_another_workers_refreshed_document_without_fetching_or_restarting_its_age(bool $refresh): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $cache = new ArrayCache();
        $factory = new HttpFactory();
        $clientA = new FakeHttpClient();
        $clientB = new FakeHttpClient();
        $workerA = new HttpSigningKeySource($clientA, $factory, 'https://partners.anis.ly', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $workerB = new HttpSigningKeySource($clientB, $factory, 'https://partners.anis.ly', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $old = $workerA->get();
        $workerB->get();
        self::assertSame(1, $clientA->calls);
        self::assertSame(0, $clientB->calls);

        $clock->advance(10);
        $clientB->body = '{"keys":[{"kid":"new-key","status":"active","notBefore":"2026-01-01T00:00:00Z"}]}';
        $workerB->refresh();
        self::assertSame(1, $clientB->calls);
        $clock->advance(5);
        $adopted = $refresh ? $workerA->refresh() : $workerA->get();
        self::assertNotSame($old, $adopted);
        self::assertSame('new-key', $adopted->keys[0]->kid);
        self::assertSame(1, $clientA->calls);
        self::assertSame($adopted, $workerA->get());
        self::assertSame(1, $clientA->calls);

        $clock->advance(294);
        self::assertSame($adopted, $workerA->get());
        self::assertSame(1, $clientA->calls);
        $clock->advance(1);
        self::assertSame([], $workerA->get()->keys);
        self::assertSame(2, $clientA->calls);
        self::assertSame(1, $clientB->calls);
    }

    #[Test]
    public function it_adopts_a_recent_shared_refresh_even_when_its_local_unknown_key_cooldown_is_running(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $cache = new ArrayCache();
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $source->get();
        $source->refresh();
        $clock->advance(1);
        $key = 'anis_partners_keys_' . substr(hash('sha256', 'https://partners.anis.ly'), 0, 32);
        $cache->set($key, json_encode(['document' => '{"keys":[{"kid":"new-key"}]}', 'fetchedAt' => $clock->now()->getTimestamp()], JSON_THROW_ON_ERROR));
        $cache->set($key . '_refresh', (float) $clock->now()->format('U.u'), 30);

        self::assertSame('new-key', $source->refresh()->keys[0]->kid);
        self::assertSame(2, $client->calls);
        self::assertSame('new-key', $source->get()->keys[0]->kid);
        self::assertSame(2, $client->calls);
    }

    #[Test]
    public function it_expires_a_shared_entry_five_minutes_after_its_original_fetch(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $cache = new ArrayCache();
        $key = 'anis_partners_keys_' . substr(hash('sha256', 'https://partners.anis.ly'), 0, 32);
        $cache->set($key, json_encode(['document' => '{"keys":[]}', 'fetchedAt' => $clock->now()->getTimestamp() - 299], JSON_THROW_ON_ERROR));
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));

        self::assertSame([], $source->get()->keys);
        self::assertSame(0, $client->calls);
        $clock->advance(1);
        self::assertSame([], $source->get()->keys);
        self::assertSame(1, $client->calls);
    }

    #[Test]
    public function it_clamps_a_future_shared_fetch_time_so_it_expires_after_three_hundred_seconds(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $cache = new ArrayCache();
        $key = 'anis_partners_keys_' . substr(hash('sha256', 'https://partners.anis.ly'), 0, 32);
        $cache->set($key, json_encode(['document' => '{"keys":[]}', 'fetchedAt' => $clock->now()->getTimestamp() + 60], JSON_THROW_ON_ERROR));
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));

        $keys = $source->get();
        self::assertSame(0, $client->calls);
        $clock->advance(299);
        self::assertSame($keys, $source->get());
        self::assertSame(0, $client->calls);
        $clock->advance(1);
        self::assertNotSame($keys, $source->get());
        self::assertSame(1, $client->calls);
    }

    #[Test]
    #[DataProvider('cooldownCaches')]
    public function it_waits_the_full_thirty_seconds_between_unknown_key_refreshes(bool $sharedCache): void
    {
        $clock = new FrozenClock(1_789_804_800, 900000);
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $sharedCache ? new ArrayCache() : null, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $source->get();
        $keys = $source->refresh();
        self::assertSame(2, $client->calls);

        $clock->advance(29, 100000);
        self::assertSame($keys, $source->refresh());
        self::assertSame(2, $client->calls);
        $clock->advance(0, 899000);
        self::assertSame($keys, $source->refresh());
        self::assertSame(2, $client->calls);
        $clock->advance(0, 1000);
        self::assertNotSame($keys, $source->refresh());
        self::assertSame(3, $client->calls);
    }

    #[Test]
    public function it_shares_the_unknown_key_cooldown_across_clients_even_when_the_fetch_fails(): void
    {
        $clock = new FrozenClock(1_789_804_800, 900000);
        $cache = new ArrayCache();
        $factory = new HttpFactory();
        $firstClient = new FakeHttpClient();
        $first = new HttpSigningKeySource($firstClient, $factory, 'https://partners.anis.ly', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $first->get();
        $firstClient->status = 503;
        try {
            $first->refresh();
            self::fail('An unavailable key document must be refused.');
        } catch (SigningKeysUnavailableException) {
            self::assertSame(2, $firstClient->calls);
        }
        $refreshKey = 'anis_partners_keys_' . substr(hash('sha256', 'https://partners.anis.ly'), 0, 32) . '_refresh';
        self::assertSame((float) $clock->now()->format('U.u'), $cache->get($refreshKey));
        self::assertSame(30, $cache->ttls[$refreshKey]);

        $secondClient = new FakeHttpClient();
        $second = new HttpSigningKeySource($secondClient, $factory, 'https://PARTNERS.anis.ly:443/', cache: $cache, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $keys = $second->get();
        self::assertSame(0, $secondClient->calls);
        self::assertSame($keys, $second->refresh());
        self::assertSame(0, $secondClient->calls);
        $clock->advance(29, 100000);
        self::assertSame($keys, $second->refresh());
        self::assertSame(0, $secondClient->calls);
        $clock->advance(0, 900000);
        self::assertNotSame($keys, $second->refresh());
        self::assertSame(1, $secondClient->calls);
        self::assertSame(2, $firstClient->calls);
    }

    #[Test]
    public function it_keeps_separate_client_cooldowns_when_no_shared_cache_is_configured(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $factory = new HttpFactory();
        $firstClient = new FakeHttpClient();
        $secondClient = new FakeHttpClient();
        $first = new HttpSigningKeySource($firstClient, $factory, 'https://partners.anis.ly', clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $second = new HttpSigningKeySource($secondClient, $factory, 'https://partners.anis.ly', clock: $clock, monotonicClock: $clock->monotonicNow(...));

        foreach ([$first, $second] as $source) {
            $source->get();
            $keys = $source->refresh();
            self::assertSame($keys, $source->refresh());
        }
        self::assertSame(2, $firstClient->calls);
        self::assertSame(2, $secondClient->calls);
    }

    #[Test]
    #[DataProvider('cooldownCaches')]
    public function it_refetches_for_an_unknown_key_at_most_once_per_thirty_seconds(bool $sharedCache): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $sharedCache ? new ArrayCache() : null, clock: $clock, monotonicClock: $clock->monotonicNow(...));
        $keys = $source->get();
        self::assertSame(1, $client->calls);
        $client->status = 503;
        try {
            $source->refresh();
            self::fail('An unavailable key document must be refused.');
        } catch (SigningKeysUnavailableException) {
            self::assertSame(2, $client->calls);
        }
        self::assertSame($keys, $source->refresh());
        self::assertSame(2, $client->calls);
        $clock->advance(29);
        self::assertSame($keys, $source->refresh());
        self::assertSame(2, $client->calls);
        $clock->advance(1);
        $client->status = 200;
        $refreshed = $source->refresh();
        self::assertNotSame($keys, $refreshed);
        self::assertSame(3, $client->calls);
        self::assertSame($refreshed, $source->refresh());
        self::assertSame(3, $client->calls);
    }

    #[Test]
    #[DataProvider('cooldownCaches')]
    public function it_does_not_freeze_the_cooldown_when_the_clock_moves_back(bool $sharedCache): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $client = new FakeHttpClient();
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', cache: $sharedCache ? new ArrayCache() : null, clock: $clock, monotonicClock: $clock->monotonicNow(...));

        $source->get();
        $source->refresh();
        self::assertSame(2, $client->calls);
        $clock->advance(-60);
        self::assertSame([], $source->refresh()->keys);
        self::assertSame(3, $client->calls);
    }

    #[Test]
    public function it_caches_in_memory_expires_refreshes_and_reuses_the_shared_cache(): void
    {
        $clock = new FrozenClock(1_789_804_800);
        $factory = new HttpFactory();
        $client = new FakeHttpClient();
        $cache = new ArrayCache();
        $source = new HttpSigningKeySource($client, $factory, 'https://partners.anis.ly', 300, $cache, $clock, monotonicClock: $clock->monotonicNow(...));

        $source->get();
        self::assertSame('identity', $client->lastRequest?->getHeaderLine('Accept-Encoding'));
        $source->get();
        self::assertSame(1, $client->calls);
        $clock->advance(301);
        $source->get();
        self::assertSame(2, $client->calls);
        $source->refresh();
        self::assertSame(3, $client->calls);

        $secondClient = new FakeHttpClient();
        $secondSource = new HttpSigningKeySource($secondClient, $factory, 'https://partners.anis.ly', 300, $cache, $clock, monotonicClock: $clock->monotonicNow(...));
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
        $keys = $source->refresh();
        self::assertSame($keys, $source->refresh());
        self::assertSame(2, $client->calls);
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
        $source = new HttpSigningKeySource($client, new HttpFactory(), $authority, 300, $cache, $clock, monotonicClock: $clock->monotonicNow(...));

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
        $source = new HttpSigningKeySource($client, new HttpFactory(), 'https://partners.anis.ly', 300, $cache, $clock, monotonicClock: $clock->monotonicNow(...));

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
