# Signing-key cache

The client fetches Anis's public response-signing keys and caches the document for ten minutes by default. A PHP-FPM
or other multi-process host has separate memory per worker. Pass a PSR-16 cache to `AnisPartnersClient::create()` so
workers reuse the same document and avoid a fetch on each new process. The cache stores only the public document and
its fetch time; an unknown key id still triggers an immediate refresh.

The cache is part of the response-verification trust boundary. Use a cache namespace and backend that only your
application can write; anyone who can replace this entry can substitute the public key used to verify Anis responses.

```php
$client = AnisPartnersClient::create($options, $signer, keyCache: $psr16Cache);
```

Laravel's cache repository and Symfony Cache adapters can be exposed through PSR-16 adapters. APCu can also provide
a local PSR-16 implementation for a single host. For multiple hosts, use a shared cache backend so each host sees
the same expiry and key rotation.

Set `signingKeyCacheSeconds` to a positive number of seconds. Keep the default unless your deployment has a reason to
refresh more often. Never cache private request keys in this cache.
