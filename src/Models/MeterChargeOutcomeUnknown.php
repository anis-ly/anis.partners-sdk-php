<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/**
 * Says the meter charge may or may not exist; resume it under the original operation id, never a new one.
 *
 * It is also the result of `idempotency_conflict` on a create or resume: the original request may already have been
 * accepted and charged, so read the meter charge by its operation id (`get()`) before any new attempt; `cause` carries the typed refusal.
 */
final readonly class MeterChargeOutcomeUnknown extends MeterChargeResult
{
    public function __construct(string $operationId, public int $suggestedDelaySeconds, public \Throwable $cause)
    {
        parent::__construct($operationId);
    }
}
