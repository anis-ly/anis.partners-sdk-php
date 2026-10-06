<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Holds the machine code and documented fields from an RFC 9457 refusal. */
final readonly class Problem implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public ?string $type = null,
        public ?string $title = null,
        public int $status = 0,
        public ?string $code = null,
        public ?string $detail = null,
        public ?string $requestId = null,
        public mixed $extensions = null,
    ) {}

    /**
     * Reads problem fields without treating localized title or detail as machine decisions.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::nullableString($data, 'type'),
            ModelData::nullableString($data, 'title'),
            ModelData::integer($data, 'status'),
            ModelData::nullableString($data, 'code'),
            ModelData::nullableString($data, 'detail'),
            ModelData::nullableString($data, 'requestId'),
            $data['extensions'] ?? null,
        );
    }
}
