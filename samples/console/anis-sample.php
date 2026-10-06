<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Models\CatalogueCard;
use Anis\Partners\Models\CatalogueCategory;
use Anis\Partners\Models\CatalogueSubcategory;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Models\MaskedCard;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\Order;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\OrderNotPlaced;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Models\OrderProcessing;
use Anis\Partners\Models\OrderReplayed;
use Anis\Partners\Models\OrderResult;
use Anis\Partners\Models\OrderStatus;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\Wallet;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\UnverifiableResponseException;
use GuzzleHttp\Client;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

final class SampleWire implements ClientInterface
{
    public function __construct(private readonly ?ClientInterface $inner, private readonly bool $dryRun, private readonly bool $preview) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $hold = $this->dryRun || ($this->preview && strtoupper($request->getMethod()) !== 'GET');
        if ($hold) {
            printf("%s — not sent\n→ %s %s\n", $this->dryRun ? 'DRY RUN' : 'PREVIEW', $request->getMethod(), (string) $request->getUri());
            echo '  headers: ' . implode(', ', array_keys($request->getHeaders())) . PHP_EOL;
            $body = (string) $request->getBody();
            echo $body === '' ? "  (no body)\n" : '  ' . safeDryRunBody($body) . PHP_EOL;
            throw new SampleDryRunComplete('The request was inspected and not sent.');
        }
        if ($this->inner === null) {
            throw new LogicException('An HTTP client is required for requests that are not held.');
        }

        return $this->inner->sendRequest($request);
    }
}

final class SampleDryRunComplete extends RuntimeException implements ClientExceptionInterface {}

/** Writes SDK debug details only when the caller asks to see them. */
final class SampleLogger extends AbstractLogger
{
    /** @param mixed $level @param string|Stringable $message @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        echo strtoupper(is_string($level) ? $level : 'notice') . ' ' . (string) $message;
        if ($context !== []) {
            echo ' ' . json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        echo PHP_EOL;
    }
}

/** A small line-oriented journal keeps the same request and id available after a process exits. */
final class OrderJournal
{
    public function __construct(private readonly string $folder) {}

    /** Writes order intent before an order request can leave this process. */
    public function save(string $operationId, string $walletId, CreateOrderRequest $request): void
    {
        if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new RuntimeException('Could not create the order folder.');
        }
        $path = $this->path($operationId);
        $json = json_encode(['operationId' => $operationId, 'walletId' => $walletId, 'request' => $request->toArray()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $oldMask = umask(0077);
        try {
            $file = fopen($path, 'x');
        } finally {
            umask($oldMask);
        }
        if ($file === false) {
            throw new RuntimeException('This operation id already has a recorded intent; resume it instead of replacing it.');
        }
        if (fwrite($file, $json) !== strlen($json)) {
            fclose($file);
            unlink($path);
            throw new RuntimeException('Could not persist the order intent.');
        }
        fclose($file);
    }

    /** Stores outcomes and newly released credentials with owner-only file permissions. */
    public function recordOutcome(string $operationId, mixed $outcome): void
    {
        $path = $this->path($operationId);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('No recorded intent for that operation id.');
        }
        $record = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record)) {
            throw new RuntimeException('The recorded order intent is incomplete.');
        }
        if ($outcome instanceof OrderCompleted) {
            $record['outcome'] = $outcome->codesWithheld ? 'completed_withheld' : 'completed';
            if ($outcome->credentials !== []) {
                $record['credentials'] = array_map(static fn(RevealedCredential $credential): array => $credential->jsonSerialize(), $outcome->credentials);
            }
        } elseif ($outcome instanceof OrderReplayed) {
            $record['outcome'] = 'replayed';
        } elseif ($outcome instanceof OrderProcessing) {
            $record['outcome'] = 'processing';
        } elseif ($outcome instanceof OrderNotPlaced) {
            $record['outcome'] = 'not_placed';
        } elseif ($outcome instanceof OrderOutcomeUnknown) {
            $record['outcome'] = 'unknown';
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $oldMask = umask(0077);
        try {
            $file = fopen($temporary, 'x');
        } finally {
            umask($oldMask);
        }
        if ($file === false) {
            throw new RuntimeException('Could not prepare the order journal update.');
        }
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (fwrite($file, $json) !== strlen($json)) {
            fclose($file);
            unlink($temporary);
            throw new RuntimeException('Could not persist the order outcome.');
        }
        fclose($file);
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Could not replace the order journal.');
        }
    }

    /** Reads a previously recorded intent for safe resumption.
     * @return array{operationId: string, walletId: string, request: array<array-key, mixed>}
     */
    public function read(string $operationId): array
    {
        $contents = file_get_contents($this->path($operationId));
        if ($contents === false) {
            throw new RuntimeException('No recorded intent for that operation id.');
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('The recorded order intent is incomplete.');
        }
        $operation = $data['operationId'] ?? null;
        $wallet = $data['walletId'] ?? null;
        $request = $data['request'] ?? null;
        if (!is_string($operation) || !is_string($wallet) || !is_array($request)) {
            throw new RuntimeException('The recorded order intent is incomplete.');
        }

        return ['operationId' => $operation, 'walletId' => $wallet, 'request' => $request];
    }

    private function path(string $operationId): string
    {
        return rtrim($this->folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $operationId . '.json';
    }
}

/** Sends only after the intent is durably recorded, so a lost answer can be resumed with the same operation id.
 * @param \Closure(string, string, CreateOrderRequest): OrderResult $send
 */
function sendJournaledOrder(OrderJournal $journal, string $operationId, string $walletId, CreateOrderRequest $request, \Closure $send): OrderResult
{
    $journal->save($operationId, $walletId, $request);
    printf("operation %s persisted before request\n", $operationId);
    $outcome = $send($walletId, $operationId, $request);
    $journal->recordOutcome($operationId, $outcome);

    return $outcome;
}

/** Resumes from the journal's original operation id and request instead of accepting replacement values.
 * @param \Closure(string, string, CreateOrderRequest): OrderResult $resume
 */
function resumeJournaledOrder(OrderJournal $journal, string $operationId, \Closure $resume): OrderResult
{
    $intent = $journal->read($operationId);
    $outcome = $resume($intent['walletId'], $intent['operationId'], CreateOrderRequest::fromArray($intent['request']));
    $journal->recordOutcome($intent['operationId'], $outcome);

    return $outcome;
}

/**
 * @param list<string> $argv
 * @return array{command: string, positional: list<string>, flags: array<string, string|bool>}
 */
function parseArguments(array $argv): array
{
    $tokens = array_slice($argv, 1);
    $command = 'help';
    if ($tokens !== [] && !str_starts_with($tokens[0], '--')) {
        $command = $tokens[0];
        array_shift($tokens);
    }
    $positional = [];
    $flags = [];
    for ($index = 0; $index < count($tokens); $index++) {
        $token = $tokens[$index];
        if (!str_starts_with($token, '--')) {
            $positional[] = $token;
            continue;
        }
        $name = substr($token, 2);
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
            $flags[$name] = $value;
        } elseif (in_array($name, ['dry-run', 'preview', 'verbose', 'use-allowed-debt', 'show-secrets'], true)) {
            $flags[$name] = true;
        } elseif (isset($tokens[$index + 1]) && !str_starts_with($tokens[$index + 1], '--')) {
            $flags[$name] = $tokens[++$index];
        } else {
            throw new InvalidArgumentException("--{$name} needs a value.");
        }
    }

    return ['command' => $command, 'positional' => $positional, 'flags' => $flags];
}

/** @return array{authority: string, keyFile: string, keyId: ?string, ordersFolder: string} */
function sampleSettings(): array
{
    $settings = [];
    foreach ([getcwd() . '/settings.json', __DIR__ . '/settings.json'] as $file) {
        if (is_file($file)) {
            $parsed = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                $settings = array_replace($settings, $parsed);
            }
        }
    }
    $sample = is_array($settings['Sample'] ?? null) ? $settings['Sample'] : $settings;
    $partner = is_array($settings['AnisPartners'] ?? null) ? $settings['AnisPartners'] : $settings;

    $authorityEnv = getenv('ANIS_PARTNERS_AUTHORITY');
    $keyFileEnv = getenv('SAMPLE_KEY_FILE');
    $keyIdEnv = getenv('SAMPLE_KEY_ID');
    $ordersFolderEnv = getenv('SAMPLE_ORDERS_FOLDER');
    $keyId = $keyIdEnv !== false && $keyIdEnv !== '' ? $keyIdEnv : ($sample['keyId'] ?? $sample['KeyId'] ?? null);

    return [
        'authority' => $authorityEnv !== false && $authorityEnv !== '' ? $authorityEnv : stringSetting($partner['authority'] ?? $partner['Authority'] ?? null, ''),
        'keyFile' => $keyFileEnv !== false && $keyFileEnv !== '' ? $keyFileEnv : stringSetting($sample['keyFile'] ?? $sample['KeyFile'] ?? null, 'partner-key.pem'),
        'keyId' => is_string($keyId) ? $keyId : null,
        'ordersFolder' => $ordersFolderEnv !== false && $ordersFolderEnv !== '' ? $ordersFolderEnv : stringSetting($sample['ordersFolder'] ?? $sample['OrdersFolder'] ?? null, 'orders'),
    ];
}

/** Resolves one string setting from optional JSON input. */
function stringSetting(mixed $value, string $default): string
{
    return is_string($value) ? $value : $default;
}

/** @param list<string> $positionals */
function argument(array $positionals, int $index, string $label): string
{
    return $positionals[$index] ?? throw new InvalidArgumentException("Missing <{$label}>.");
}

/** Persists a generated enrollment key before sending its public half to Anis. */
function persistEnrollmentKeyFile(string $path, string $pem): void
{
    $oldMask = umask(0077);
    try {
        $file = fopen($path, 'x');
    } finally {
        umask($oldMask);
    }
    if ($file === false) {
        throw new RuntimeException('Could not create the private key file; choose a new path.');
    }
    if (!chmod($path, 0600)) {
        fclose($file);
        unlink($path);
        throw new RuntimeException('Could not restrict the private key file to its owner.');
    }
    if (fwrite($file, $pem) !== strlen($pem)) {
        fclose($file);
        unlink($path);
        throw new RuntimeException('Could not save the private key.');
    }
    fclose($file);
}

/** @return numeric-string */
function decimalAmount(string $amount): string
{
    if (!is_numeric($amount) || preg_match('/\A[+-]?(?:(?:\d+)(?:\.\d*)?|\.\d+)\z/D', $amount) !== 1) {
        throw new InvalidArgumentException('Expected unit price must be a decimal amount.');
    }

    return $amount;
}

/** Prints safe, useful output without exposing credentials. */
function show(mixed $value): void
{
    if ($value instanceof OrderCompleted) {
        printf($value->codesWithheld ? "COMPLETED — CODES WITHHELD operation=%s\n" : "COMPLETED operation=%s credentials=%d\n", ...($value->codesWithheld ? [$value->operationId] : [$value->operationId, count($value->credentials)]));
        return;
    }
    if ($value instanceof OrderProcessing) {
        if ($value->order->status === OrderStatus::RecoveryExhausted) {
            printf("RECOVERY EXHAUSTED operation=%s; keep the same id and contact support@anis.ly.\n", $value->operationId);
            return;
        }
        printf("PROCESSING operation=%s resume_after=%ds\n", $value->operationId, $value->retryAfterSeconds);
        return;
    }
    if ($value instanceof OrderReplayed) {
        printf("REPLAYED operation=%s credentials=not repeated\n", $value->operationId);
        return;
    }
    if ($value instanceof OrderNotPlaced) {
        printf("NOT PLACED operation=%s code=%s\n", $value->operationId, $value->refusal->errorCode->value);
        return;
    }
    if ($value instanceof Order && $value->status === OrderStatus::RecoveryExhausted) {
        printf("RECOVERY EXHAUSTED operation=%s; keep the same id and contact support@anis.ly.\n", $value->operationId);
        return;
    }
    if ($value instanceof OrderOutcomeUnknown) {
        if ($value->cause instanceof SampleDryRunComplete) {
            throw $value->cause;
        }
        printf("UNKNOWN operation=%s resume_after=%ds cause=%s\n", $value->operationId, $value->suggestedDelaySeconds, $value->cause::class);
        printf("Resume this same operation id and exact request: resume %s\n", $value->operationId);
        return;
    }
    if ($value instanceof JsonSerializable) {
        $value = $value->jsonSerialize();
    }
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL;
}

/** Formats a random UUID v4 for a new, independently identified purchase. */
function newOperationId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function usage(): void
{
    echo <<<'HELP'
Anis Partner SDK sample

  enrol --invitation <uuid> [--key-file <path>] [--days 365]
  enrol-status --invitation <uuid]
  tour | profile | wallets | wallet <wallet>
  categories <wallet> | subcategories <wallet> <category>
  subcategory <wallet> <subcategory> | cards <wallet> <subcategory>
  order <wallet> <subcategory> <card> <qty> [--reference <text>] [--use-allowed-debt]
        [--operation <uuid>] [--expected-unit-price <amount>]
  resume <operation> | order-status <operation>
  owned <wallet> | owned-card <wallet> <sold-card>
  reveal <wallet> <sold-card> | reveal-invoice <wallet> <invoice>
  diagnostic | signing-keys | help

Options: --dry-run, --preview (reads only; hold the first change), --verbose, --show-secrets. Settings: settings.json or ANIS_PARTNERS_AUTHORITY,
SAMPLE_KEY_FILE, SAMPLE_KEY_ID, SAMPLE_ORDERS_FOLDER.

HELP;
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $parsed = parseArguments($argv);
    $command = $parsed['command'];
    $args = $parsed['positional'];
    $flags = $parsed['flags'];
    if ($command === 'help' || $command === '--help') {
        usage();
        return 0;
    }
    $settings = sampleSettings();
    $authority = $settings['authority'];
    if ($authority === '') {
        throw new InvalidArgumentException('Set ANIS_PARTNERS_AUTHORITY or authority in settings.json.');
    }
    $dryRun = isset($flags['dry-run']) || getenv('SAMPLE_DRY_RUN') === '1';
    $preview = isset($flags['preview']);
    $http = new SampleWire(new Client(['timeout' => 15, 'allow_redirects' => false]), $dryRun, $preview);
    $logger = isset($flags['verbose']) ? new SampleLogger() : null;
    $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
    $streamFactory = Psr17FactoryDiscovery::findStreamFactory();

    if ($command === 'enrol' || $command === 'enrol-status') {
        $invitation = (string) ($flags['invitation'] ?? throw new InvalidArgumentException('--invitation <uuid> is required.'));
        $tokenValue = getenv('SAMPLE_ENROLLMENT_TOKEN');
        if (!is_string($tokenValue) || $tokenValue === '') {
            throw new InvalidArgumentException('Set SAMPLE_ENROLLMENT_TOKEN for the one-use enrollment token.');
        }
        $token = $tokenValue;
        $enrollment = EnrollmentClient::create($authority, $invitation, $token, $http, $requestFactory, $streamFactory, logger: $logger);
        if ($command === 'enrol-status') {
            show($enrollment->getStatus());
            return 0;
        }
        $keyFile = is_string($flags['key-file'] ?? null) ? $flags['key-file'] : $settings['keyFile'];
        $days = filter_var($flags['days'] ?? '365', FILTER_VALIDATE_INT);
        if (!is_int($days) || $days < 1) {
            throw new InvalidArgumentException('--days must be a positive whole number.');
        }
        $persistKey = !$dryRun && !$preview;
        if ($persistKey && is_file($keyFile)) {
            throw new InvalidArgumentException('The key file already exists; choose a new file for this enrollment.');
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Could not generate the P-256 private key.');
        }
        if (!is_string($pem)) {
            throw new RuntimeException('OpenSSL did not return the private key PEM.');
        }
        if ($persistKey) {
            persistEnrollmentKeyFile($keyFile, $pem);
        }
        $signer = PemP256Signer::fromPem($pem);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = $enrollment->submitKey(new EnrollmentKeyRequest($signer->publicJwk(), $now, $now->modify('+' . $days . ' days')));
        $status = $enrollment->prove($result, $signer);
        if ($status->proofState !== 'accepted') {
            throw new RuntimeException('Anis did not accept the key proof; stop enrollment and contact Anis staff.');
        }
        printf("key id %s\nsafety code %s — Anis staff will call your technical contact to verify it.\nproof state %s\n", $result->keyId, $result->safetyCode ?? '', $status->proofState ?? 'unknown');
        return 0;
    }

    if ($settings['keyId'] === null) {
        throw new InvalidArgumentException('Set SAMPLE_KEY_ID to the key id Anis issued.');
    }
    $signer = PemP256Signer::fromPemFile($settings['keyFile'])->forKey($settings['keyId']);
    $options = new ClientOptions($authority);
    $client = AnisPartnersClient::create($options, $signer, $http, $requestFactory, $streamFactory, logger: $logger);
    $journal = new OrderJournal($settings['ordersFolder']);

    switch ($command) {
        case 'profile': show($client->profile()->get());
            break;
        case 'wallets': foreach ($client->wallets()->list() as $item) {
            show($item);
        } break;
        case 'wallet': show($client->wallets()->get(argument($args, 0, 'wallet id')));
            break;
        case 'categories': show($client->catalogue()->listCategoriesPage(argument($args, 0, 'wallet id')));
            break;
        case 'subcategories': show($client->catalogue()->listSubcategoriesPage(argument($args, 0, 'wallet id'), argument($args, 1, 'category id')));
            break;
        case 'subcategory': show($client->catalogue()->getSubcategory(argument($args, 0, 'wallet id'), argument($args, 1, 'subcategory id')));
            break;
        case 'cards': show($client->catalogue()->listCardsPage(argument($args, 0, 'wallet id'), argument($args, 1, 'subcategory id')));
            break;
        case 'owned': foreach ($client->ownedCards()->list(argument($args, 0, 'wallet id')) as $item) {
            if (!$item instanceof MaskedCard) {
                throw new RuntimeException('An owned-card page contained an unexpected value.');
            }
            show(['id' => $item->id, 'card' => $item->card, 'credentialAvailable' => $item->credentialAvailable]);
        } break;
        case 'owned-card': show($client->ownedCards()->get(argument($args, 0, 'wallet id'), argument($args, 1, 'sold card id')));
            break;
        case 'reveal':
            $credential = $client->ownedCards()->reveal(argument($args, 0, 'wallet id'), argument($args, 1, 'sold card id'));
            show(credentialView($credential, isset($flags['show-secrets'])));
            break;
        case 'reveal-invoice':
            $credentials = $client->ownedCards()->revealInvoice(argument($args, 0, 'wallet id'), argument($args, 1, 'invoice id'));
            show(array_map(static fn(RevealedCredential $item): array => credentialView($item, isset($flags['show-secrets'])), $credentials->items));
            break;
        case 'diagnostic': show($client->diagnostics()->checkSignature());
            break;
        case 'signing-keys':
            $keys = new HttpSigningKeySource($http, $requestFactory, $authority, logger: $logger);
            show($keys->refresh());
            break;
        case 'order-status': show($client->orders()->get(argument($args, 0, 'operation id')));
            break;
        case 'resume':
            $outcome = resumeJournaledOrder($journal, argument($args, 0, 'operation id'), static fn(string $walletId, string $operationId, CreateOrderRequest $request): OrderResult => $client->orders()->resume($walletId, $operationId, $request));
            show($outcome);
            break;
        case 'order':
            $walletId = argument($args, 0, 'wallet id');
            $subcategoryId = argument($args, 1, 'subcategory id');
            $cardId = argument($args, 2, 'card id');
            $quantity = filter_var(argument($args, 3, 'quantity'), FILTER_VALIDATE_INT);
            if (!is_int($quantity)) {
                throw new InvalidArgumentException('Quantity must be a whole number.');
            }
            $card = null;
            foreach ($client->catalogue()->listCards($walletId, $subcategoryId) as $candidate) {
                if (!$candidate instanceof CatalogueCard) {
                    throw new RuntimeException('A catalogue page contained an unexpected value.');
                }
                if ($candidate->id === strtolower($cardId)) {
                    $card = $candidate;
                    break;
                }
            }
            if (!$card instanceof CatalogueCard) {
                throw new RuntimeException('Card was not found in this wallet catalogue.');
            }
            $unitPrice = $card->unitPrice;
            if ($unitPrice === null) {
                throw new RuntimeException('The card has no wallet price and cannot be sold to this wallet.');
            }
            if (is_string($flags['expected-unit-price'] ?? null)) {
                $unitPrice = Money::of(decimalAmount($flags['expected-unit-price']), $unitPrice->currency);
            }
            $request = new CreateOrderRequest($card->id, $quantity, $unitPrice, $unitPrice->multiply($quantity), is_string($flags['reference'] ?? null) ? $flags['reference'] : null, isset($flags['use-allowed-debt']));
            $operationId = is_string($flags['operation'] ?? null) ? $flags['operation'] : newOperationId();
            $outcome = sendJournaledOrder($journal, $operationId, $walletId, $request, static fn(string $walletId, string $operationId, CreateOrderRequest $request): OrderResult => $client->orders()->create($walletId, $operationId, $request));
            show($outcome);
            break;
        case 'tour':
            show($client->profile()->get());
            foreach ($client->wallets()->list() as $wallet) {
                if (!$wallet instanceof Wallet) {
                    throw new RuntimeException('A wallet page contained an unexpected value.');
                }
                show($wallet);
                $firstWallet = $wallet;
                break;
            }
            if (isset($firstWallet)) {
                show($client->wallets()->get($firstWallet->id));
                $categories = $client->catalogue()->listCategoriesPage($firstWallet->id);
                show($categories);
                $category = $categories->items[0] ?? null;
                if ($category instanceof CatalogueCategory) {
                    $subcategories = $client->catalogue()->listSubcategoriesPage($firstWallet->id, $category->id);
                    show($subcategories);
                    $subcategory = $subcategories->items[0] ?? null;
                    if ($subcategory instanceof CatalogueSubcategory) {
                        show($client->catalogue()->getSubcategory($firstWallet->id, $subcategory->id));
                        show($client->catalogue()->listCardsPage($firstWallet->id, $subcategory->id));
                    }
                }
                foreach ($client->ownedCards()->list($firstWallet->id) as $ownedCard) {
                    if (!$ownedCard instanceof MaskedCard) {
                        throw new RuntimeException('An owned-card page contained an unexpected value.');
                    }
                    show(['id' => $ownedCard->id, 'credentialAvailable' => $ownedCard->credentialAvailable]);
                    show($client->ownedCards()->get($firstWallet->id, $ownedCard->id));
                    break;
                }
            }
            show($client->diagnostics()->checkSignature());
            show((new HttpSigningKeySource($http, $requestFactory, $authority, logger: $logger))->refresh());
            break;
        default: throw new InvalidArgumentException("Unknown command '{$command}'. Run with help.");
    }

    return 0;
}

/** @return array<string, string|null> */
function credentialView(RevealedCredential $credential, bool $showSecrets): array
{
    return [
        'soldCardId' => $credential->soldCardId,
        'serialNumber' => $showSecrets ? $credential->serialNumber : maskSecret($credential->serialNumber),
        'voucher' => $showSecrets ? $credential->voucher : maskSecret($credential->voucher),
        'expiryDate' => $credential->expiryDate,
    ];
}

/** Masks credentials in the terminal unless a partner explicitly opts in. */
function maskSecret(?string $secret): ?string
{
    if ($secret === null) {
        return null;
    }

    return strlen($secret) < 5 ? '****' : (strlen($secret) <= 8 ? '****' . substr($secret, -2) : substr($secret, 0, 2) . str_repeat('*', strlen($secret) - 4) . substr($secret, -2));
}

/** Hides proof signatures and private JWK members if a dry-run body contains any. */
function safeDryRunBody(string $body): string
{
    $value = json_decode($body, true);
    if (!is_array($value)) {
        return '[body omitted because it is not a JSON object]';
    }

    return json_encode(redactSensitiveFields($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<array-key, mixed> $value
 * @return array<array-key, mixed>
 */
function redactSensitiveFields(array $value): array
{
    foreach ($value as $key => $item) {
        if (in_array(strtolower((string) $key), ['signature', 'd', 'privatekey', 'token'], true)) {
            $value[$key] = '[redacted]';
        } elseif (is_array($item)) {
            $value[$key] = redactSensitiveFields($item);
        }
    }

    return $value;
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    try {
        /** @var list<string> $scriptArguments */
        $scriptArguments = $_SERVER['argv'] ?? [];
        exit(main($scriptArguments));
    } catch (SampleDryRunComplete) {
        echo "Request held; nothing was sent.\n";
        exit(0);
    } catch (AnisApiException $exception) {
        printf("REFUSED %s (%d) request=%s type=%s\n", $exception->rawCode ?? 'unknown', $exception->status, $exception->requestId ?? 'unavailable', $exception::class);
        exit(2);
    } catch (UnverifiableResponseException $exception) {
        fwrite(STDERR, 'UNVERIFIABLE RESPONSE (' . $exception->failure()->value . '); content was discarded.' . PHP_EOL);
        exit(3);
    } catch (ClientExceptionInterface $exception) {
        fwrite(STDERR, 'NO ANSWER (' . $exception::class . '). A read can be retried; resume an order with its same operation id.' . PHP_EOL);
        exit(4);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
