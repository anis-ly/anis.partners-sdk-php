<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\Internal\SystemClock;
use OpenTelemetry\API\Globals;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/** Fetches and caches the public key document Anis publishes for response verification. */
final class HttpSigningKeySource implements SigningKeySource
{
    private ?SigningKeySet $memory = null;
    private ?int $fetchedAt = null;
    private readonly ClockInterface $clock;
    private readonly string $cacheKey;

    /**
     * Configures HTTP retrieval and optional shared caching so separate PHP workers reuse fresh keys.
     * Keeps these public partner values stable after construction.
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $authority,
        private readonly int $cacheSeconds = 600,
        private readonly ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        // PHP-FPM starts a process per request, so a shared cache avoids fetching keys on every page.
        $this->cacheKey = 'anis-partners.signing-keys.' . hash('sha256', strtolower($authority));
    }

    /** Returns fresh cached keys or fetches the document when this cache entry expires. */
    public function get(): SigningKeySet
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->memory !== null && $this->fetchedAt !== null && $now - $this->fetchedAt < $this->cacheSeconds) {
            return $this->memory;
        }
        $expired = $this->fetchedAt !== null;

        if ($this->cache !== null) {
            $cached = $this->cache->get($this->cacheKey);
            if (is_string($cached)) {
                try {
                    $entry = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($entry) && is_string($entry['document'] ?? null) && is_int($entry['fetchedAt'] ?? null)) {
                        $keySet = SigningKeySet::fromJson($entry['document']);
                        if ($now - $entry['fetchedAt'] < $this->cacheSeconds) {
                            $this->memory = $keySet;
                            $this->fetchedAt = $entry['fetchedAt'];

                            return $keySet;
                        }
                        $expired = true;
                    }
                } catch (\JsonException|\UnexpectedValueException) {
                    $expired = true;
                }
            }
        }

        return $this->fetch($expired ? 'expired' : 'first-use');
    }

    /** Fetches the key document even when the cached copy is still fresh. */
    public function refresh(): SigningKeySet
    {
        return $this->fetch('refresh');
    }

    private function fetch(string $reason): SigningKeySet
    {
        try {
            $uri = rtrim($this->authority, '/') . '/.well-known/partner-signing-keys.json';
            $request = $this->requestFactory->createRequest('GET', $uri)->withHeader('Accept-Encoding', 'identity');
            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new SigningKeysUnavailableException();
            }
            $contentEncoding = $response->getHeaderLine('Content-Encoding');
            if ($contentEncoding !== '' && strtolower(trim($contentEncoding)) !== 'identity') {
                throw new SigningKeysUnavailableException();
            }
            $document = (string) $response->getBody();
            $keys = SigningKeySet::fromJson($document);
            $now = $this->clock->now()->getTimestamp();
            $this->memory = $keys;
            $this->fetchedAt = $now;
            if ($this->cache !== null) {
                $entry = json_encode(['document' => $document, 'fetchedAt' => $now], JSON_THROW_ON_ERROR);
                $this->cache->set($this->cacheKey, $entry, $this->cacheSeconds);
            }
        } catch (SigningKeysUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new SigningKeysUnavailableException($exception);
        }
        try {
            Globals::meterProvider()->getMeter('anis-ly/partners')->createCounter('anis.partners.signing_keys.fetches')->add(1, ['anis.fetch.reason' => $reason]);
        } catch (\Throwable) {
        }
        \Anis\Partners\Observability\Log::write($this->logger ?? new \Psr\Log\NullLogger(), 'info', 'Fetched the Anis signing-key document', 1005, ['reason' => $reason, 'key_count' => count($keys->keys)]);

        return $keys;
    }
}
