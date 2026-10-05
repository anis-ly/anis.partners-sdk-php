<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Sample;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SampleTest extends TestCase
{
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
                '--token', 'one-use-test-token',
                '--key-file', $keyFile,
                '--dry-run',
            ], ['ANIS_PARTNERS_AUTHORITY' => 'https://partners.example']);

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
}
