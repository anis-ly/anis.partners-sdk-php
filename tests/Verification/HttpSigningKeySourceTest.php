<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Verification;

use Anis\Partners\Tests\Support\ArrayCache;
use Anis\Partners\Tests\Support\FakeHttpClient;
use Anis\Partners\Tests\Support\FrozenClock;
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
