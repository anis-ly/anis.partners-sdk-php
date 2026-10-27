<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/**
 * Carries an accepted create or recipient correction with its read Location and retry delay.
 * Read later or wait for a webhook. Only a create may be resumed with its original request; a correction is never replayed automatically.
 */
final readonly class DirectRechargeProcessing extends DirectRechargeResult
{
    /** @param DirectRecharge|null $read The latest full read when Anis answered one that is not final yet. */
    public function __construct(string $operationId, public int $retryAfterSeconds = 5, public ?string $location = null, public ?DirectRecharge $read = null)
    {
        parent::__construct($operationId);
    }
}
