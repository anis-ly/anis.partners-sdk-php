<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkdownPhpExamplesTest extends TestCase
{
    #[Test]
    public function it_syntax_and_phpstan_checks_every_php_fence_in_the_readme_and_docs(): void
    {
        $root = dirname(__DIR__, 2);
        $docFiles = glob($root . '/docs/*.md');
        $documents = array_merge([$root . '/README.md'], is_array($docFiles) ? $docFiles : []);
        $checked = 0;

        foreach ($documents as $document) {
            $markdown = file_get_contents($document);
            self::assertNotFalse($markdown, 'Could not read ' . basename($document));
            preg_match_all('/^```php([^\n]*)\n(.*?)^```\s*$/ms', $markdown, $matches);

            foreach ($matches[2] as $index => $example) {
                // Blocks tagged `php laravel` use the framework, which this package does not depend on: they are
                // syntax-checked here and were type-checked in a Laravel application when written.
                $laravel = str_contains($matches[1][$index], 'laravel');
                $file = tempnam(sys_get_temp_dir(), 'anis-php-doc-');
                self::assertNotFalse($file, 'Could not create a temporary PHP example file.');
                try {
                    $source = str_starts_with(ltrim($example), '<?php') ? $example : "<?php\n" . $example;
                    self::assertNotFalse(file_put_contents($file, $source));
                    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    self::assertIsResource($process, 'Could not start php -l for ' . basename($document));
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $exitCode = proc_close($process);
                    self::assertSame(0, $exitCode, basename($document) . ' PHP block ' . ($index + 1) . " failed php -l:\n" . $stdout . $stderr);
                    if ($laravel) {
                        $checked++;
                        continue;
                    }

                    $imports = [];
                    preg_match_all('/^use [^;]+;\s*$/m', $source, $importMatches);
                    foreach ($importMatches[0] as $import) {
                        $imports[] = trim($import);
                    }
                    $imports = array_merge($imports, [
                        'use Anis\\Partners\\AnisPartnersClient;',
                        'use Anis\\Partners\\ClientOptions;',
                        'use Anis\\Partners\\Enrollment\\EnrollmentClient;',
                        'use Anis\\Partners\\Models\\CreateOrderRequest;',
                        'use Anis\\Partners\\Models\\EnrollmentKeyRequest;',
                        'use Anis\\Partners\\Models\\Money;',
                        'use Anis\\Partners\\Models\\OrderCompleted;',
                        'use Anis\\Partners\\Models\\OrderNotPlaced;',
                        'use Anis\\Partners\\Models\\OrderOutcomeUnknown;',
                        'use Anis\\Partners\\Models\\OrderProcessing;',
                        'use Anis\\Partners\\Models\\OrderReplayed;',
                        'use Anis\\Partners\\Signing\\P256Signer;',
                        'use Anis\\Partners\\Signing\\PemP256Signer;',
                    ]);
                    $snippet = preg_replace('/^use [^;]+;\s*$/m', '', $source) ?? $source;
                    $snippet = preg_replace('/^require __DIR__.*;\s*$/m', '', $snippet) ?? $snippet;
                    $analysisSource = "<?php\ndeclare(strict_types=1);\n" . implode("\n", array_unique($imports)) . "\n" . self::stubPreamble() . "\n" . preg_replace('/^<\\?php\s*/', '', $snippet);
                    self::assertNotFalse(file_put_contents($file, $analysisSource));
                    $phpstan = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpstan', 'analyse', '--configuration=' . dirname(__DIR__, 2) . '/phpstan.neon.dist', '--debug', '--no-progress', '--memory-limit=1G', '--error-format=raw', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $stanPipes, dirname(__DIR__, 2));
                    self::assertIsResource($phpstan, 'Could not start PHPStan for ' . basename($document));
                    $stanOut = stream_get_contents($stanPipes[1]);
                    $stanError = stream_get_contents($stanPipes[2]);
                    fclose($stanPipes[1]);
                    fclose($stanPipes[2]);
                    $stanExit = proc_close($phpstan);
                    self::assertNotFalse($stanOut);
                    self::assertNotFalse($stanError);
                    self::assertSame(0, $stanExit, basename($document) . ' PHP block ' . ($index + 1) . " failed PHPStan:\n" . $stanOut . $stanError);
                    $checked++;
                } finally {
                    unlink($file);
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'Expected PHP examples in README.md or docs/*.md.');
    }

    private static function stubPreamble(): string
    {
        return <<<'PHP'
$invitationId = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
$enrollmentToken = 'documentation-token';
$keyId = '3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43';
$walletId = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
$subcategoryId = '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046';
$signer = new class implements \Anis\Partners\Signing\RequestSigner {
    public function keyId(): string { return '3f2a9c14-8d6e-4b21-9f07-5c8ab2d61e43'; }
    public function sign(#[\SensitiveParameter] string $data): string { return str_repeat("\0", 64); }
};
$options = new \Anis\Partners\ClientOptions('https://partners.example');
$client = \Anis\Partners\AnisPartnersClient::create($options, $signer);
$uuidGenerator = new class { public function v4(): string { return '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34'; } };
$journal = new class {
    public function save(string $operationId, string $walletId, \Anis\Partners\Models\CreateOrderRequest $request): void {}
};
function persistOrderIntent(string $operationId, string $walletId, \Anis\Partners\Models\CreateOrderRequest $request): void {}
/** @param list<\Anis\Partners\Models\RevealedCredential> $credentials */
function storeCredentialsSecurely(array $credentials): void {}
$card = new \Anis\Partners\Models\CatalogueCard(
    '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
    '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046',
    unitPrice: \Anis\Partners\Models\Money::of('10.5', 'LYD'),
);
$psr16Cache = new \Anis\Partners\Tests\Support\ArrayCache();
class KmsClient
{
    public function signP256Sha256(string $data): string { return str_repeat("\0", 64); }
}
PHP;
    }
}
