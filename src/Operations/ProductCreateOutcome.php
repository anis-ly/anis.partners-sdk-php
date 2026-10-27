<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Errors\AnisApiException;

/** @internal Carries what a product create proved, before each product turns it into its own result type. */
final readonly class ProductCreateOutcome
{
    private function __construct(
        public string $kind,
        public string $operationId,
        public int $delaySeconds = 0,
        public ?string $location = null,
        public ?object $read = null,
        public bool $replayed = false,
        public ?AnisApiException $refusal = null,
        public ?\Throwable $cause = null,
    ) {}

    public static function open(string $operationId, int $delay, ?string $location, ?object $read): self
    {
        return new self('open', $operationId, $delay, $location, $read);
    }

    public static function closed(string $operationId, object $read, bool $replayed): self
    {
        return new self('final', $operationId, 0, null, $read, $replayed);
    }

    public static function refused(string $operationId, AnisApiException $refusal): self
    {
        return new self('refused', $operationId, 0, null, null, false, $refusal);
    }

    public static function unknown(string $operationId, int $delay, \Throwable $cause): self
    {
        return new self('unknown', $operationId, $delay, null, null, false, null, $cause);
    }
}
