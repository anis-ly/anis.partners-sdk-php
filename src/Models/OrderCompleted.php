<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Represents first delivery of a completed sale and its credentials, if released. */
final readonly class OrderCompleted extends OrderResult
{
    /** @var list<RevealedCredential> */
    public array $credentials;
    public bool $codesWithheld;

    /** Carries released credentials only on first completion so a replay cannot expose them twice. */
    public function __construct(public Order $order)
    {
        parent::__construct($order->operationId);
        $this->credentials = $order->soldCards ?? [];
        $this->codesWithheld = $order->codesWithheld === true || $this->credentials === [];
    }

    /** Prevents credential-bearing order data from appearing in result inspection. */
    public function __debugInfo(): array
    {
        return ['operationId' => $this->operationId, 'outcome' => 'completed', 'credentials' => '<redacted>'];
    }
}
