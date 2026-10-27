<?php

declare(strict_types=1);

namespace Anis\Partners;

use Anis\Partners\Internal\Uuid;
use Anis\Partners\Operations\AccessOperations;
use Anis\Partners\Operations\CatalogueOperations;
use Anis\Partners\Operations\DiagnosticsOperations;
use Anis\Partners\Operations\DirectRechargeOperations;
use Anis\Partners\Operations\DirectRechargeProductOperations;
use Anis\Partners\Operations\MeterChargeOperations;
use Anis\Partners\Operations\OrderOperations;
use Anis\Partners\Operations\OwnedCardOperations;
use Anis\Partners\Operations\PartnerTransport;
use Anis\Partners\Operations\ProfileOperations;
use Anis\Partners\Operations\WalletOperations;
use Anis\Partners\Operations\WebhookEndpointOperations;
use Anis\Partners\Operations\WebhookEventOperations;
use Anis\Partners\Signing\NonceFactory;
use Anis\Partners\Signing\RandomNonceFactory;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\PartnerResponseVerifier;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/** Provides typed operations over requests that are always signed and answers that are verified on every route Anis signs. */
final class AnisPartnersClient
{
    public const VERSION = '2.2.0';
    private function __construct(
        private readonly AccessOperations $accessOperations,
        private readonly ProfileOperations $profileOperations,
        private readonly WalletOperations $walletOperations,
        private readonly CatalogueOperations $catalogueOperations,
        private readonly OrderOperations $orderOperations,
        private readonly OwnedCardOperations $ownedCardOperations,
        private readonly DiagnosticsOperations $diagnosticsOperations,
        private readonly MeterChargeOperations $meterChargeOperations,
        private readonly DirectRechargeProductOperations $directRechargeProductOperations,
        private readonly DirectRechargeOperations $directRechargeOperations,
        private readonly WebhookEndpointOperations $webhookEndpointOperations,
        private readonly WebhookEventOperations $webhookEventOperations,
    ) {}

    /** Builds the complete signing and verifying client for a host without requiring a container. */
    public static function create(
        ClientOptions $options,
        RequestSigner $signer,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        ?CacheInterface $keyCache = null,
        ?LoggerInterface $logger = null,
        ?NonceFactory $nonceFactory = null,
    ): self {
        try {
            Uuid::canonical($signer->keyId(), 'key id');
        } catch (\Throwable $exception) {
            throw new RequestSigningException($exception);
        }
        $http ??= Psr18ClientDiscovery::find();
        $requests ??= Psr17FactoryDiscovery::findRequestFactory();
        $streams ??= Psr17FactoryDiscovery::findStreamFactory();
        $clock = new \Anis\Partners\Internal\SystemClock();
        $keys = new HttpSigningKeySource($http, $requests, $options->authority, $options->signingKeyCacheSeconds, $keyCache, $clock, $logger, $options->name);
        $verifier = new PartnerResponseVerifier($keys, $clock, $logger, $options->name);
        $transport = new PartnerTransport($http, $requests, $streams, $options, $signer, $verifier, $clock, $logger, $nonceFactory ?? new RandomNonceFactory());

        return new self(
            new AccessOperations($transport),
            new ProfileOperations($transport),
            new WalletOperations($transport),
            new CatalogueOperations($transport),
            new OrderOperations($transport, $logger),
            new OwnedCardOperations($transport),
            new DiagnosticsOperations($transport),
            new MeterChargeOperations($transport, $logger),
            new DirectRechargeProductOperations($transport),
            new DirectRechargeOperations($transport, $logger),
            new WebhookEndpointOperations($transport),
            new WebhookEventOperations($transport),
        );
    }

    /** Returns application suspension and key revocation operations. */
    public function access(): AccessOperations
    {
        return $this->accessOperations;
    }

    /** Returns the live profile operation group so permissions are re-read on demand. */
    public function profile(): ProfileOperations
    {
        return $this->profileOperations;
    }
    /** Returns wallet reads for every grant assigned to this application. */
    public function wallets(): WalletOperations
    {
        return $this->walletOperations;
    }
    /** Returns the wallet-priced catalogue operations. */
    public function catalogue(): CatalogueOperations
    {
        return $this->catalogueOperations;
    }
    /** Returns safe order creation and recovery operations. */
    public function orders(): OrderOperations
    {
        return $this->orderOperations;
    }
    /** Returns masked-card and explicit reveal operations. */
    public function ownedCards(): OwnedCardOperations
    {
        return $this->ownedCardOperations;
    }
    /** Returns the signature diagnostic operation. */
    public function diagnostics(): DiagnosticsOperations
    {
        return $this->diagnosticsOperations;
    }
    /** Returns meter packages and safe meter charging with receipts, tokens and refunds. */
    public function meterCharges(): MeterChargeOperations
    {
        return $this->meterChargeOperations;
    }
    /** Returns the direct recharge products, places and quotes of a wallet. */
    public function directRechargeProducts(): DirectRechargeProductOperations
    {
        return $this->directRechargeProductOperations;
    }
    /** Returns safe direct recharge creation, follow-up and paused-recipient correction. */
    public function directRecharges(): DirectRechargeOperations
    {
        return $this->directRechargeOperations;
    }
    /** Returns the notification endpoint of the application. */
    public function webhookEndpoint(): WebhookEndpointOperations
    {
        return $this->webhookEndpointOperations;
    }
    /** Returns the notification deliveries of the application. */
    public function webhookEvents(): WebhookEventOperations
    {
        return $this->webhookEventOperations;
    }
}
