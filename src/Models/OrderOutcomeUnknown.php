<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents an unresolved order that must be resumed under its original operation ID. */
final readonly class OrderOutcomeUnknown extends OrderResult
{
    /**
     * Keeps the cause available for local handling while preventing accidental new-order retries.
     *
     */
    public function __construct(
        string $operationId,
        public int $suggestedDelaySeconds,
        public \Throwable $cause,
    ) {
        parent::__construct($operationId);
    }
}
