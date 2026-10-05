<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use Psr\Log\LoggerInterface;

/** Captures structured events so tests can inspect fields without a logger dependency. */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** Keeps level and context as data so assertions can distinguish event ids from formatted prose. */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
