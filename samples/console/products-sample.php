<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Models\CreateDirectRechargeRequest;
use Anis\Partners\Models\CreateMeterChargeRequest;
use Anis\Partners\Models\DirectRechargeAmountIn;
use Anis\Partners\Models\DirectRechargeFinal;
use Anis\Partners\Models\DirectRechargeNotPlaced;
use Anis\Partners\Models\DirectRechargeOutcomeUnknown;
use Anis\Partners\Models\DirectRechargeProcessing;
use Anis\Partners\Models\DirectRechargeRecipientInput;
use Anis\Partners\Models\DirectRechargeResult;
use Anis\Partners\Models\MeterChargeFinal;
use Anis\Partners\Models\MeterChargeNotPlaced;
use Anis\Partners\Models\MeterChargeOutcomeUnknown;
use Anis\Partners\Models\MeterChargeProcessing;
use Anis\Partners\Models\MeterChargeResult;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\SetWebhookEndpointRequest;
use Anis\Partners\Models\UpdateDirectRechargeRecipientRequest;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Verification\UnverifiableResponseException;
use GuzzleHttp\Client;

/**
 * Meter charges, direct recharges and webhook endpoint management for the console.
 *
 * Every create writes its operation id and exact request to the journal folder before anything is sent, and a resume
 * reads that record back. Meter tokens and recipient details are never printed.
 */
function settingText(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException("Set {$name}.");
    }

    return $value;
}

/** @param array<string, mixed> $record */
function journalWrite(string $folder, string $operationId, array $record): void
{
    if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('The journal folder could not be created.');
    }
    $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $path = $folder . '/' . $operationId . '.json';
    $previousMask = umask(0077);
    try {
        $file = @fopen($path, 'x+b');
    } finally {
        umask($previousMask);
    }
    if ($file === false) {
        throw new RuntimeException('The journal entry could not be created exclusively; use resume for an existing id.');
    }
    try {
        if (!@chmod($path, 0600)) {
            throw new RuntimeException('The journal entry permissions could not be secured.');
        }
        $stat = fstat($file);
        if ($stat === false || ($stat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('The journal entry permissions are not private.');
        }
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = @fwrite($file, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The complete journal entry could not be written.');
            }
            $offset += $written;
        }
        if (!fflush($file) || !fsync($file)) {
            throw new RuntimeException('The journal entry could not be flushed durably.');
        }
    } finally {
        $closed = fclose($file);
    }
    if (!$closed) {
        throw new RuntimeException('The journal entry could not be closed.');
    }
}

/** @return array<array-key, mixed> */
function journalRead(string $folder, string $operationId): array
{
    $json = file_get_contents($folder . '/' . $operationId . '.json');
    if ($json === false) {
        throw new InvalidArgumentException('There is no journal entry for this operation id.');
    }
    $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record)) {
        throw new UnexpectedValueException('The journal entry is not an object.');
    }

    return $record;
}

/** Reads an amount argument as dinars, refusing anything that is not a decimal number. */
function dinars(string $amount): Money
{
    if (!is_numeric($amount)) {
        throw new InvalidArgumentException('Give amounts as decimal numbers with at most three places.');
    }

    return Money::of($amount, 'LYD');
}

function newProductOperationId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function meterOutcome(MeterChargeResult $result): void
{
    match (true) {
        $result instanceof MeterChargeFinal => printf("Closed: %s (%d tokens)\n", $result->charge->status->value, count($result->charge->receipt->tokens ?? [])),
        $result instanceof MeterChargeProcessing => printf("Processing. Wait %d seconds, then resume the same operation id.\n", $result->retryAfterSeconds),
        $result instanceof MeterChargeNotPlaced => printf("Not placed: %s. Use a new operation id for a new attempt.\n", $result->refusal->rawCode ?? 'refused'),
        $result instanceof MeterChargeOutcomeUnknown => printf("Unknown. Resume %s with the same request after %d seconds.\n", $result->operationId, $result->suggestedDelaySeconds),
        default => throw new LogicException('Unexpected meter charge result.'),
    };
}

function rechargeOutcome(DirectRechargeResult $result): void
{
    match (true) {
        $result instanceof DirectRechargeFinal => printf("Closed: %s\n", $result->recharge->status->value),
        $result instanceof DirectRechargeProcessing => printf("In progress. Wait %d seconds, then resume or read the same operation id.\n", $result->retryAfterSeconds),
        $result instanceof DirectRechargeNotPlaced => printf("Not placed: %s. Use a new operation id for a new attempt.\n", $result->refusal->rawCode ?? 'refused'),
        $result instanceof DirectRechargeOutcomeUnknown => printf("Unknown. Resume %s with the same request after %d seconds.\n", $result->operationId, $result->suggestedDelaySeconds),
        default => throw new LogicException('Unexpected direct recharge result.'),
    };
}

/** @param list<string> $argv */
function productsMain(array $argv, ?AnisPartnersClient $client = null): int
{
    $command = $argv[1] ?? 'help';
    $args = array_slice($argv, 2);
    if ($command === 'help') {
        echo "meter-packages <wallet> | meter-charge <wallet> <package> <meter> <total> | meter-resume <op> | meter-status <op>\n"
            . "recharge-products <wallet> | recharge-quote <wallet> <product> <amount> <lyd|fx> | recharge <wallet> <product> <payout> <total> <key=value>... | recharge-resume <op> | recharge-status <op> | recharge-fix <op> <key=value>...\n"
            . "webhook-endpoint | webhook-set <url> | webhook-test | webhook-events | webhook-redeliver <event>\n";

        return 0;
    }
    $client ??= AnisPartnersClient::create(
        new ClientOptions(settingText('ANIS_PARTNERS_AUTHORITY')),
        PemP256Signer::fromPemFile(settingText('SAMPLE_KEY_FILE'))->forKey(settingText('SAMPLE_KEY_ID')),
        new Client(['timeout' => 15, 'allow_redirects' => false]),
    );
    $folder = getenv('SAMPLE_PRODUCTS_FOLDER');
    $journal = is_string($folder) && $folder !== '' ? $folder : sys_get_temp_dir() . '/anis-products';

    try {
        switch ($command) {
            case 'meter-packages':
                foreach ($client->meterCharges()->getMeterPackages($args[0] ?? '') as $package) {
                    printf("%s %s pays %s\n", $package->id, $package->name, $package->unitPrice->amount());
                }
                break;
            case 'meter-charge':
                $request = new CreateMeterChargeRequest($args[1] ?? '', $args[2] ?? '', dinars($args[3] ?? ''));
                $operationId = newProductOperationId();
                journalWrite($journal, $operationId, ['walletId' => $args[0] ?? '', 'request' => $request->toArray()]);
                echo "operation id {$operationId}\n";
                meterOutcome($client->meterCharges()->create($args[0] ?? '', $operationId, $request));
                break;
            case 'meter-resume':
                $record = journalRead($journal, $args[0] ?? '');
                $request = CreateMeterChargeRequest::fromArray(is_array($record['request'] ?? null) ? $record['request'] : []);
                meterOutcome($client->meterCharges()->resume(is_string($record['walletId'] ?? null) ? $record['walletId'] : '', $args[0] ?? '', $request));
                break;
            case 'meter-status':
                $read = $client->meterCharges()->get($args[0] ?? '');
                printf("%s\n", $read instanceof \Anis\Partners\Models\MeterCharge ? $read->status->value : 'processing');
                break;
            case 'recharge-products':
                foreach ($client->directRechargeProducts()->getDirectRechargeProducts($args[0] ?? '') as $product) {
                    printf("%s %s %s\n", $product->id, $product->title, $product->available ? 'available' : 'unavailable');
                }
                break;
            case 'recharge-quote':
                $quote = $client->directRechargeProducts()->quote($args[0] ?? '', $args[1] ?? '', $args[2] ?? '', DirectRechargeAmountIn::from($args[3] ?? 'lyd'));
                printf("pays out %s %s for %s %s\n", $quote->payout->amount, $quote->payout->currency->name, $quote->total->amount(), $quote->total->currency);
                break;
            case 'recharge':
                $details = [];
                foreach (array_slice($args, 4) as $pair) {
                    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                    $details[] = new DirectRechargeRecipientInput($key, $value);
                }
                $request = new CreateDirectRechargeRequest($args[1] ?? '', $args[2] ?? '', dinars($args[3] ?? ''), $details);
                $operationId = newProductOperationId();
                journalWrite($journal, $operationId, ['walletId' => $args[0] ?? '', 'request' => $request->toArray()]);
                echo "operation id {$operationId}\n";
                rechargeOutcome($client->directRecharges()->create($args[0] ?? '', $operationId, $request));
                break;
            case 'recharge-resume':
                $record = journalRead($journal, $args[0] ?? '');
                $request = CreateDirectRechargeRequest::fromArray(is_array($record['request'] ?? null) ? $record['request'] : []);
                rechargeOutcome($client->directRecharges()->resume(is_string($record['walletId'] ?? null) ? $record['walletId'] : '', $args[0] ?? '', $request));
                break;
            case 'recharge-status':
                $read = $client->directRecharges()->get($args[0] ?? '');
                printf("%s\n", $read instanceof \Anis\Partners\Models\DirectRecharge ? $read->status->value : 'processing');
                break;
            case 'recharge-fix':
                $details = [];
                foreach (array_slice($args, 1) as $pair) {
                    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                    $details[] = new DirectRechargeRecipientInput($key, $value);
                }
                $acknowledgement = $client->directRecharges()->updateRecipient($args[0] ?? '', new UpdateDirectRechargeRecipientRequest($details));
                printf("Correction accepted for %s. Read %s after %ds or wait for its webhook; the read may still show the old state.\n", $acknowledgement->operationId, $acknowledgement->location, $acknowledgement->retryAfterSeconds);
                break;
            case 'webhook-endpoint':
                $endpoint = $client->webhookEndpoint()->get();
                printf("%s %s\n", $endpoint->status->value, $endpoint->url ?? '-');
                break;
            case 'webhook-set':
                printf("%s\n", $client->webhookEndpoint()->set(new SetWebhookEndpointRequest($args[0] ?? ''))->status->value);
                break;
            case 'webhook-test':
                printf("queued %s\n", $client->webhookEndpoint()->test()->id);
                break;
            case 'webhook-events':
                foreach ($client->webhookEvents()->getWebhookEvents() as $event) {
                    printf("%s %s %s attempts %d\n", $event->id, $event->rawType, $event->status->value, $event->attempts);
                }
                break;
            case 'webhook-redeliver':
                printf("queued %s\n", $client->webhookEvents()->redeliver($args[0] ?? '')->id);
                break;
            default:
                fwrite(STDERR, "Unknown command. Run with help.\n");

                return 2;
        }
    } catch (UnverifiableResponseException $error) {
        fwrite(STDERR, 'The answer was discarded: ' . $error->failure()->value . "\n");

        return 1;
    }

    return 0;
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
$arguments = $_SERVER['argv'] ?? null;
if (PHP_SAPI === 'cli' && is_string($script) && realpath($script) === __FILE__ && is_array($arguments)) {
    exit(productsMain(array_values(array_filter($arguments, is_string(...)))));
}
