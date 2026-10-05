<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Docs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkdownPhpExamplesTest extends TestCase
{
    #[Test]
    public function it_syntax_checks_every_php_fence_in_the_readme_and_docs(): void
    {
        $root = dirname(__DIR__, 2);
        $docFiles = glob($root . '/docs/*.md');
        $documents = array_merge([$root . '/README.md'], is_array($docFiles) ? $docFiles : []);
        $checked = 0;

        foreach ($documents as $document) {
            $markdown = file_get_contents($document);
            self::assertNotFalse($markdown, 'Could not read ' . basename($document));
            preg_match_all('/^```php[^\n]*\n(.*?)^```\s*$/ms', $markdown, $matches);

            foreach ($matches[1] as $index => $example) {
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
                    $checked++;
                } finally {
                    unlink($file);
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'Expected PHP examples in README.md or docs/*.md.');
    }
}
