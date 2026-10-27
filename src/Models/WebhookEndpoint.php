<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes the application's webhook endpoint; all three members are always present, with null when nothing is configured. */
final readonly class WebhookEndpoint implements \JsonSerializable
{
    public function __construct(
        public ?string $url,
        public WebhookEndpointStatus $status,
        public ?int $generation,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        ModelData::requireMembers($data, 'url', 'generation');

        return new self(
            ModelData::nullableString($data, 'url'),
            WebhookEndpointStatus::parse(ModelData::requiredString($data, 'status')),
            ModelData::nullableInteger($data, 'generation'),
        );
    }

    /**
     * Keeps explicit nulls so the three required members are never dropped.
     * @return array{url: ?string, status: string, generation: ?int}
     */
    public function jsonSerialize(): array
    {
        return ['url' => $this->url, 'status' => $this->status->value, 'generation' => $this->generation];
    }
}
