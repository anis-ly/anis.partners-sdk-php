<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Sets the HTTPS address that receives notifications. */
final readonly class SetWebhookEndpointRequest implements \JsonSerializable
{
    public function __construct(public string $url)
    {
        if ($url === '') {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A webhook endpoint needs a URL.');
        }
    }

    /** @return array{url: string} */
    public function jsonSerialize(): array
    {
        return ['url' => $this->url];
    }

    public function toJson(): string
    {
        return ModelData::json($this->jsonSerialize());
    }
}
