<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Sample;

use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\Order;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Models\OrderReplayed;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SampleTest extends TestCase
{
    #[Test]
    public function it_journals_credentials_supplied_with_a_completed_withheld_result(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $folder = sys_get_temp_dir() . '/anis-order-withheld-codes-' . bin2hex(random_bytes(6));
        $journal = new \OrderJournal($folder);
        $operationId = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';

        try {
            $journal->save($operationId, '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')));
            $credential = new RevealedCredential('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', voucher: 'supplied-withheld-voucher');
            $journal->recordOutcome($operationId, new OrderCompleted(new Order($operationId, soldCards: [$credential], codesWithheld: true)));

            $stored = json_decode((string) file_get_contents($folder . '/' . $operationId . '.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($stored);
            self::assertSame('completed_withheld', $stored['outcome'] ?? null);
            $credentials = $stored['credentials'] ?? null;
            self::assertIsArray($credentials);
            $storedCredential = $credentials[0] ?? null;
            self::assertIsArray($storedCredential);
            self::assertSame('supplied-withheld-voucher', $storedCredential['voucher'] ?? null);
        } finally {
            self::removeJournalFolder($folder);
        }
    }

    #[Test]
    public function order_journal_persists_intent_and_keeps_credentials_after_replay(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $folder = sys_get_temp_dir() . '/anis-order-journal-' . bin2hex(random_bytes(6));
        $journal = new \OrderJournal($folder);
        $operationId = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
        $request = new CreateOrderRequest(
            '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48',
            1,
            Money::of('10.5', 'LYD'),
            Money::of('10.5', 'LYD'),
        );

        try {
            $journal->save($operationId, '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', $request);
            $path = $folder . '/' . $operationId . '.json';
            self::assertFileExists($path);
            self::assertSame(0600, fileperms($path) & 0777);
            $intent = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($intent);
            self::assertSame($operationId, $intent['operationId'] ?? null);
            $requestData = $intent['request'] ?? null;
            self::assertIsArray($requestData);
            $expectedTotal = $requestData['expectedTotal'] ?? null;
            self::assertIsArray($expectedTotal);
            self::assertSame('10.500', $expectedTotal['amount'] ?? null);

            $credential = new RevealedCredential('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', voucher: 'stored-voucher');
            $journal->recordOutcome($operationId, new OrderCompleted(new Order($operationId, soldCards: [$credential])));
            $journal->recordOutcome($operationId, new OrderReplayed(new Order($operationId)));
            $stored = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($stored);
            self::assertSame('replayed', $stored['outcome'] ?? null);
            $credentials = $stored['credentials'] ?? null;
            self::assertIsArray($credentials);
            $storedCredential = $credentials[0] ?? null;
            self::assertIsArray($storedCredential);
            self::assertSame('stored-voucher', $storedCredential['voucher'] ?? null);
            $journal->recordOutcome($operationId, new OrderCompleted(new Order($operationId, codesWithheld: true)));
            $afterWithheld = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($afterWithheld);
            self::assertSame('completed_withheld', $afterWithheld['outcome'] ?? null);
            $afterCredentials = $afterWithheld['credentials'] ?? null;
            self::assertIsArray($afterCredentials);
            $afterCredential = $afterCredentials[0] ?? null;
            self::assertIsArray($afterCredential);
            self::assertSame('stored-voucher', $afterCredential['voucher'] ?? null);
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            $files = glob($folder . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            if (is_dir($folder)) {
                rmdir($folder);
            }
        }
    }

    #[Test]
    public function it_records_order_intent_before_invoking_the_order_sender(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $folder = sys_get_temp_dir() . '/anis-order-before-send-' . bin2hex(random_bytes(6));
        $journal = new \OrderJournal($folder);
        $operationId = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
        $walletId = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
        $request = new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD'));
        $sent = false;
        $bufferLevel = ob_get_level();

        try {
            ob_start();
            $result = \sendJournaledOrder($journal, $operationId, $walletId, $request, static function (string $sentWalletId, string $sentOperationId, CreateOrderRequest $sentRequest) use ($folder, $operationId, $walletId, $request, &$sent): OrderCompleted {
                $path = $folder . '/' . $operationId . '.json';
                self::assertFileExists($path, 'Intent must be durable before the sender is invoked.');
                $intent = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($intent);
                self::assertSame($operationId, $intent['operationId'] ?? null);
                self::assertSame($walletId, $sentWalletId);
                self::assertSame($operationId, $sentOperationId);
                self::assertSame($request->toArray(), $sentRequest->toArray());
                $sent = true;

                return new OrderCompleted(new Order($operationId));
            });
            ob_end_clean();
            self::assertTrue($sent);
            self::assertInstanceOf(OrderCompleted::class, $result);
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            self::removeJournalFolder($folder);
        }
    }

    #[Test]
    public function resume_uses_the_journal_operation_id_and_request(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $folder = sys_get_temp_dir() . '/anis-order-resume-' . bin2hex(random_bytes(6));
        $journal = new \OrderJournal($folder);
        $operationId = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
        $request = new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD'));
        try {
            $journal->save($operationId, '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', $request);
            $result = \resumeJournaledOrder($journal, $operationId, static function (string $walletId, string $sentOperationId, CreateOrderRequest $sentRequest) use ($operationId, $request): OrderCompleted {
                self::assertSame('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', $walletId);
                self::assertSame($operationId, $sentOperationId);
                self::assertSame($request->toArray(), $sentRequest->toArray());

                return new OrderCompleted(new Order($sentOperationId));
            });
            self::assertInstanceOf(OrderCompleted::class, $result);
        } finally {
            self::removeJournalFolder($folder);
        }
    }

    #[Test]
    public function an_unknown_resume_keeps_credentials_already_saved_in_the_journal(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $folder = sys_get_temp_dir() . '/anis-order-unknown-' . bin2hex(random_bytes(6));
        $journal = new \OrderJournal($folder);
        $operationId = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
        try {
            $journal->save($operationId, '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', new CreateOrderRequest('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48', 1, Money::of('10.5', 'LYD'), Money::of('10.5', 'LYD')));
            $credential = new RevealedCredential('4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046', voucher: 'retained-voucher');
            $journal->recordOutcome($operationId, new OrderCompleted(new Order($operationId, soldCards: [$credential])));
            \resumeJournaledOrder($journal, $operationId, static function (string $walletId, string $sentOperationId, CreateOrderRequest $request) use ($operationId): OrderOutcomeUnknown {
                self::assertSame('2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', $walletId);
                self::assertSame($operationId, $sentOperationId);

                return new OrderOutcomeUnknown($sentOperationId, 60, new \RuntimeException('connection lost'));
            });
            $stored = json_decode((string) file_get_contents($folder . '/' . $operationId . '.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($stored);

            self::assertSame('unknown', $stored['outcome'] ?? null);
            $credentials = $stored['credentials'] ?? null;
            self::assertIsArray($credentials);
            $storedCredential = $credentials[0] ?? null;
            self::assertIsArray($storedCredential);
            self::assertSame('retained-voucher', $storedCredential['voucher'] ?? null);
        } finally {
            self::removeJournalFolder($folder);
        }
    }

    #[Test]
    public function dry_run_wire_never_calls_the_underlying_http_client(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $inner = new FakeHttpClient();
        $factory = new HttpFactory();
        $wire = new \SampleWire($inner, true, false);
        ob_start();

        try {
            $wire->sendRequest($factory->createRequest('POST', 'https://partners.example/v1/orders'));
            self::fail('A dry-run order must be held before reaching the HTTP client.');
        } catch (\SampleDryRunComplete) {
            self::assertSame(0, $inner->calls);
        } finally {
            ob_end_clean();
        }
    }

    #[Test]
    public function help_prints_usage_without_network_access(): void
    {
        $result = $this->runSample(['help']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('Anis Partner SDK sample', $result['stdout']);
        self::assertStringContainsString('reveal-invoice', $result['stdout']);
    }

    #[Test]
    public function dry_run_prints_a_signed_request_to_the_stub_and_sends_nothing(): void
    {
        $folder = sys_get_temp_dir() . '/anis-sample-' . bin2hex(random_bytes(6));
        mkdir($folder, 0700);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        $keyFile = $folder . '/partner-key.pem';
        self::assertNotFalse(file_put_contents($keyFile, $pem));
        $process = $this->runSample(['profile', '--dry-run'], [
            'ANIS_PARTNERS_AUTHORITY' => 'https://partners.example',
            'SAMPLE_KEY_FILE' => $keyFile,
            'SAMPLE_KEY_ID' => '3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43',
            'SAMPLE_DRY_RUN' => '1',
        ]);
        unlink($keyFile);
        rmdir($folder);

        self::assertSame(0, $process['code']);
        self::assertStringContainsString('DRY RUN — not sent', $process['stdout']);
        self::assertStringContainsString('Signature-Input', $process['stdout']);
        self::assertStringNotContainsString('Signature: ', $process['stdout']);
        self::assertStringContainsString('nothing was sent', $process['stdout']);
    }

    #[Test]
    public function enrollment_dry_run_never_creates_a_private_key_file(): void
    {
        $folder = sys_get_temp_dir() . '/anis-enrol-dry-run-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($folder, 0700));
        $keyFile = $folder . '/partner-key.pem';

        try {
            $process = $this->runSample([
                'enrol',
                '--invitation', '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26',
                '--key-file', $keyFile,
                '--dry-run',
            ], ['ANIS_PARTNERS_AUTHORITY' => 'https://partners.example', 'SAMPLE_ENROLLMENT_TOKEN' => 'one-use-test-token']);

            self::assertFileDoesNotExist($keyFile);
            self::assertStringContainsString('DRY RUN — not sent', $process['stdout']);
        } finally {
            if (is_file($keyFile)) {
                unlink($keyFile);
            }
            rmdir($folder);
        }
    }

    #[Test]
    public function a_real_enrollment_key_file_is_written_with_owner_only_permissions(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/anis-sample.php';
        $folder = sys_get_temp_dir() . '/anis-enrol-key-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($folder, 0700));
        $keyFile = $folder . '/partner-key.pem';
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        try {
            \persistEnrollmentKeyFile($keyFile, $pem);

            self::assertFileExists($keyFile);
            self::assertSame(0600, fileperms($keyFile) & 0777);
            self::assertSame($pem, file_get_contents($keyFile));
        } finally {
            if (is_file($keyFile)) {
                unlink($keyFile);
            }
            rmdir($folder);
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runSample(array $arguments, array $environment = []): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/samples/console/anis-sample.php', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function removeJournalFolder(string $folder): void
    {
        $files = glob($folder . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($folder)) {
            rmdir($folder);
        }
    }
}
