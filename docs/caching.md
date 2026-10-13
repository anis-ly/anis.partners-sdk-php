# Signing-key cache

The client fetches Anis's public response-signing keys the first time it verifies a signed answer (an order, a
reveal, an enrollment step, or the signature self-check) and caches the document for five minutes by default
(at most five minutes).
Information reads are not signed by Anis and never fetch the keys. A PHP-FPM
or other multi-process host has separate memory per worker. Pass a PSR-16 cache to `AnisPartnersClient::create()` so
workers reuse the same document and avoid a fetch on each new process. The cache stores the public document, its
fetch time and the last unknown-key refresh time; an unknown key id triggers a refresh at most once every 30 seconds,
including failed fetches.

With a PSR-16 cache configured, the last unknown-key refresh time is stored under `<cacheKey>_refresh` with a
30-second TTL. Clients using that cache share the cooldown for the same authority, including across PHP-FPM
requests. The timestamp retains fractional seconds so a refresh cannot start before the full 30 seconds elapse.
When a recent shared marker exists, a worker adopts a newer cached document without fetching it or restarting its
age. The PSR-16 marker uses separate read and write calls, so it is not atomic: workers arriving simultaneously can
each fetch once. The marker still suppresses subsequent refreshes during the 30-second window.
Without a shared cache, the cooldown applies to each client instance: per request under PHP-FPM, or across requests
when a long-running worker (queues, Octane) reuses its client. If the cache is unavailable, the in-memory cooldown
still applies. First-use and expired-document fetches do not start the unknown-key cooldown.

The cache is part of the response-verification trust boundary. Use a cache namespace and backend that only your
application can write; anyone who can replace this entry can substitute the public key used to verify Anis's signed
answers.

```php
$client = AnisPartnersClient::create($options, $signer, keyCache: $psr16Cache);
```

Laravel's cache repository and Symfony Cache adapters can be exposed through PSR-16 adapters. APCu can also provide
a local PSR-16 implementation for a single host. For multiple hosts, use a shared cache backend so each host sees
the same expiry and key rotation.

Set `signingKeyCacheSeconds` to 1–300 seconds. The default, 300, is also the maximum: the SDK contributes at most
300 seconds to the revocation delay. The end-to-end bound is approximately seven minutes, including the gateway's
60-second key-metadata cache and the key document's HTTP `max-age=60`, followed by the SDK's 300 seconds.
A shared entry expires after the configured lifetime from when Anis's document was first fetched, whichever worker
copies it. The in-memory copy uses a monotonic deadline, so moving the wall clock backwards cannot extend its trust.
Never cache private request keys in this cache.
