# Using the SDK with Laravel

The SDK works in Laravel as it is; there is no separate Laravel package. Three small files give you one shared client,
settings from `.env`, Laravel's cache shared by every worker for Anis's public signing keys, and the SDK's logs in your
Laravel log. Checked with Laravel 13.

## 1. Settings

```dotenv
ANIS_PARTNERS_AUTHORITY=https://<the address Anis gave you>
ANIS_PARTNERS_KEY_FILE=/secure/partner-key.pem
ANIS_PARTNERS_KEY_ID=<the key id Anis issued>
ANIS_PARTNERS_LANGUAGE=Arabic
```

`config/anis-partners.php`:

```php laravel
<?php

return [
    // The HTTPS address Anis gave you.
    'authority' => env('ANIS_PARTNERS_AUTHORITY'),
    // Your private key file, readable only by the application user, and the key id Anis issued for it.
    'key_file' => env('ANIS_PARTNERS_KEY_FILE'),
    'key_id' => env('ANIS_PARTNERS_KEY_ID'),
    // Arabic, English or Unspecified: the language of refusal titles and catalogue text.
    'accept_language' => env('ANIS_PARTNERS_LANGUAGE', 'Arabic'),
    // The cache store every worker shares for Anis's public signing keys (null = your default store).
    'key_cache_store' => env('ANIS_PARTNERS_KEY_CACHE_STORE'),
];
```

## 2. The service provider

Create it with `php artisan make:provider AnisPartnersServiceProvider` — Laravel registers it in
`bootstrap/providers.php` for you — and replace its contents with:

```php laravel
<?php

namespace App\Providers;

use Anis\Partners\AcceptLanguage;
use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Signing\PemP256Signer;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class AnisPartnersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One client for the whole application: it keeps Anis's signing keys between calls.
        $this->app->singleton(AnisPartnersClient::class, function (Application $app): AnisPartnersClient {
            $config = $app['config']['anis-partners'];

            return AnisPartnersClient::create(
                new ClientOptions(
                    $config['authority'],
                    acceptLanguage: AcceptLanguage::from($config['accept_language']),
                ),
                PemP256Signer::fromPemFile($config['key_file'])->forKey($config['key_id']),
                // Finite timeouts, and never follow a redirect with a signed request.
                http: new GuzzleClient(['timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false]),
                // Laravel's cache is a PSR-16 cache: every PHP-FPM worker shares the key document.
                keyCache: $app['cache']->store($config['key_cache_store']),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }
}
```

**The key cache store** must be one that every worker shares and only your application can write — `redis`,
`database`, `memcached`, or `file` on a single server. Never `array`: it forgets everything after each request, so every
order, reveal or enrollment request would fetch Anis's keys again. Anyone who can write that cache entry could replace the
keys used to verify Anis's signed answers.

## 3. Use it

Ask for `AnisPartnersClient` wherever Laravel injects dependencies:

```php laravel
use Anis\Partners\AnisPartnersClient;

final class WalletController
{
    public function index(AnisPartnersClient $anis): array
    {
        $wallets = [];
        foreach ($anis->wallets()->list() as $wallet) {
            $wallets[] = ['id' => $wallet->id, 'name' => $wallet->name, 'balance' => $wallet->balance->amount()];
        }

        return $wallets;
    }
}
```

## Orders in a queued job

Save the order — its operation id and the exact request — in your database **before** you dispatch the job, and keep
the job's retries off (`public $tries = 1;` or a closed failure handler) so Laravel never sends a second request on its
own. In the job:

- `OrderCompleted`: store the credentials first, then mark the order done.
- `OrderProcessing` or `OrderOutcomeUnknown`: dispatch the job again with `->delay()` set to the suggested wait, and
  call `orders()->resume()` with the **same** operation id and request. Never create a new id for it.
- `OrderNotPlaced`: nothing was bought; close the order.

See [Orders and recovery](orders-and-recovery.md) for every rule.
