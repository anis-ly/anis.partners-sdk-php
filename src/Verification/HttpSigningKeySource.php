<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\AnisPartnersInvalidArgumentException;
use Anis\Partners\Internal\SystemClock;
use Anis\Partners\Observability\AnisPartnersTelemetry;
use Anis\Partners\Observability\Log;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/** Fetches and caches the public key document Anis publishes for response verification. */
final class HttpSigningKeySource implements SigningKeySource
{
    /** Shortest gap between re-fetches caused by unknown key ids, so forged answers cannot make workers flood Anis. */
    private const UNKNOWN_KEY_REFETCH_COOLDOWN_SECONDS = 30;

    private ?SigningKeySet $memory = null;
    private ?string $document = null;
    private ?float $memoryDeadline = null;
    /** @var \Closure(): float */
    private readonly \Closure $monotonicClock;
    private ?int $fetchedAt = null;
    private ?int $sharedFetchedAt = null;
    private ?float $unknownKeyFetchAt = null;
    private readonly ClockInterface $clock;
    private readonly string $cacheKey;

    /**
     * Configures HTTP retrieval and optional shared caching so separate PHP workers reuse fresh keys.
     * @param (\Closure(): float)|null $monotonicClock Nanoseconds from a monotonic clock; defaults to hrtime(true).
     *
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $authority,
        private readonly int $cacheSeconds = ClientOptions::MAX_SIGNING_KEY_CACHE_SECONDS,
        private readonly ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $clientName = 'default',
        ?\Closure $monotonicClock = null,
    ) {
        if ($cacheSeconds > ClientOptions::MAX_SIGNING_KEY_CACHE_SECONDS) {
            throw new AnisPartnersInvalidArgumentException('HttpSigningKeySource::$cacheSeconds must be at most 300 seconds.');
        }
        $this->clock = $clock ?? new SystemClock();
        $this->monotonicClock = $monotonicClock ?? static fn(): float => (float) hrtime(true);
        // PHP-FPM starts a process per request, so a shared cache avoids fetching keys on every page.
        $normalizedAuthority = self::normalizeAuthority($authority);
        $this->cacheKey = 'anis_partners_keys_' . substr(hash('sha256', $normalizedAuthority), 0, 32);
    }

    /** Returns fresh cached keys or fetches the document when this cache entry expires. */
    public function get(): SigningKeySet
    {
        $memory = $this->memory;
        $deadline = $this->memoryDeadline;
        if ($memory !== null && $deadline !== null && ($this->monotonicClock)() < $deadline) {
            if ($this->recentSharedRefreshAt() !== null) {
                $shared = $this->readShared();
                if ($shared['keys'] !== null) {
                    return $shared['keys'];
                }
            }

            if (($this->monotonicClock)() < $deadline) {
                return $memory;
            }
        }
        $expired = $this->fetchedAt !== null;
        $shared = $this->readShared();
        if ($shared['keys'] !== null) {
            return $shared['keys'];
        }

        return $this->fetch($expired || $shared['expired'] ? 'expired' : 'first-use');
    }

    /** Re-fetches after an unknown key at most once per 30 seconds; inside that window the held document answers. */
    public function refresh(): SigningKeySet
    {
        $now = (float) $this->clock->now()->format('U.u');
        $elapsed = $this->unknownKeyFetchAt === null ? null : $now - $this->unknownKeyFetchAt;
        if ($elapsed !== null && $elapsed >= 0 && $elapsed < self::UNKNOWN_KEY_REFETCH_COOLDOWN_SECONDS) {
            return $this->keysDuringCooldown();
        }
        $lastRefresh = $this->recentSharedRefreshAt();
        if ($lastRefresh !== null) {
            $this->unknownKeyFetchAt = $lastRefresh;

            return $this->keysDuringCooldown();
        }
        $this->unknownKeyFetchAt = $now;
        if ($this->cache !== null) {
            try {
                // Publish before fetching so an unavailable document also starts the cross-request cooldown.
                $this->cache->set($this->cacheKey . '_refresh', $now, self::UNKNOWN_KEY_REFETCH_COOLDOWN_SECONDS);
            } catch (\Throwable) {
            }
        }

        return $this->fetch('refresh');
    }

    private function recentSharedRefreshAt(): ?float
    {
        if ($this->cache === null) {
            return null;
        }
        try {
            $lastRefresh = $this->cache->get($this->cacheKey . '_refresh');
        } catch (\Throwable) {
            return null;
        }
        if (!is_int($lastRefresh) && !is_float($lastRefresh)) {
            return null;
        }
        if (!is_finite((float) $lastRefresh)) {
            return null;
        }
        $elapsed = (float) $this->clock->now()->format('U.u') - $lastRefresh;

        return $elapsed >= 0 && $elapsed < self::UNKNOWN_KEY_REFETCH_COOLDOWN_SECONDS ? (float) $lastRefresh : null;
    }

    private function keysDuringCooldown(): SigningKeySet
    {
        $shared = $this->readShared();
        if ($shared['keys'] !== null) {
            return $shared['keys'];
        }
        $memory = $this->memory;
        $deadline = $this->memoryDeadline;
        if ($memory !== null && $deadline !== null && ($this->monotonicClock)() < $deadline) {
            return $memory;
        }

        throw new SigningKeysUnavailableException();
    }

    /** @return array{keys: ?SigningKeySet, expired: bool} */
    private function readShared(): array
    {
        if ($this->cache === null) {
            return ['keys' => null, 'expired' => false];
        }
        try {
            $cached = $this->cache->get($this->cacheKey);
        } catch (\Throwable) {
            return ['keys' => null, 'expired' => false];
        }
        if (!is_string($cached)) {
            return ['keys' => null, 'expired' => false];
        }
        try {
            $entry = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || !is_string($entry['document'] ?? null) || !is_int($entry['fetchedAt'] ?? null)) {
                return ['keys' => null, 'expired' => false];
            }
            $now = $this->clock->now()->getTimestamp();
            $monotonicNow = ($this->monotonicClock)();
            // A shared entry keeps its original fetch time, so copying it never restarts its configured lifetime.
            $fetchedAt = min($entry['fetchedAt'], $now);
            $sameDocument = $this->document === $entry['document'] && $this->sharedFetchedAt === $entry['fetchedAt'];
            if ($sameDocument && $this->fetchedAt !== null) {
                $fetchedAt = min($fetchedAt, $this->fetchedAt);
            }
            $deadline = $monotonicNow + ($fetchedAt + $this->cacheSeconds - $now) * 1_000_000_000;
            if ($sameDocument && $this->memoryDeadline !== null) {
                // Re-reading the same shared copy must not restore time lost to a wall-clock rollback.
                $deadline = min($deadline, $this->memoryDeadline);
            }
            if ($entry['fetchedAt'] > $now + 60 || $now >= $fetchedAt + $this->cacheSeconds || $monotonicNow >= $deadline) {
                return ['keys' => null, 'expired' => true];
            }
            $keys = $sameDocument && $this->memory !== null ? $this->memory : SigningKeySet::fromJson($entry['document']);
            $this->memory = $keys;
            $this->document = $entry['document'];
            $this->fetchedAt = $fetchedAt;
            $this->sharedFetchedAt = $entry['fetchedAt'];
            $this->memoryDeadline = $deadline;

            return ['keys' => $keys, 'expired' => false];
        } catch (\JsonException|\Anis\Partners\Errors\AnisPartnersUnexpectedValueException) {
            return ['keys' => null, 'expired' => true];
        }
    }

    private function fetch(string $reason): SigningKeySet
    {
        try {
            $uri = rtrim($this->authority, '/') . '/.well-known/partner-signing-keys.json';
            $request = $this->requestFactory->createRequest('GET', $uri)->withHeader('Accept-Encoding', 'identity')
                ->withHeader('User-Agent', 'anis-partners-php/' . \Anis\Partners\AnisPartnersClient::VERSION);
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
            $this->document = $document;
            $this->fetchedAt = $now;
            $this->sharedFetchedAt = $now;
            // Wall-clock corrections cannot extend the local trust window.
            $this->memoryDeadline = ($this->monotonicClock)() + $this->cacheSeconds * 1_000_000_000;
            if ($this->cache !== null) {
                $entry = json_encode(['document' => $document, 'fetchedAt' => $now], JSON_THROW_ON_ERROR);
                try {
                    $this->cache->set($this->cacheKey, $entry, $this->cacheSeconds);
                } catch (\Throwable) {
                    try {
                        ($this->logger ?? new NullLogger())->warning('Could not cache the Anis signing-key document: {reason}', ['reason' => 'cache_write_failed']);
                    } catch (\Throwable) {
                    }
                }
            }
        } catch (SigningKeysUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new SigningKeysUnavailableException($exception);
        }
        AnisPartnersTelemetry::signingKeysFetched($reason, count($keys->keys), $this->clientName);
        Log::write($this->logger ?? new NullLogger(), 'info', 'Fetched the Anis signing-key document: {reason}, {key_count} keys', 1005, ['reason' => $reason, 'key_count' => count($keys->keys)]);

        return $keys;
    }

    private static function normalizeAuthority(string $authority): string
    {
        $parts = parse_url($authority);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return rtrim(strtolower($authority), '/');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (str_contains($host, ':')) {
            $packed = inet_pton(trim($host, '[]'));
            if ($packed !== false) {
                $host = '[' . strtolower((string) inet_ntop($packed)) . ']';
            }
        }
        $port = $parts['port'] ?? null;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme . '://' . $host . ($port === null || $defaultPort ? '' : ':' . $port);
    }
}
