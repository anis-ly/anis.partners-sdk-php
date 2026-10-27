<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Says why a meter charge failed; the raw code is kept so a code this SDK does not know is never lost. */
final readonly class MeterFailure implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public MeterFailureCode $code,
        public string $rawCode,
        public LocalizedText $message,
    ) {}

    /**
     * Reads the failure; the message is optional and never decides handling.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $message = ModelData::object($data, 'message');

        return new self(
            MeterFailureCode::parse(ModelData::requiredString($data, 'code')),
            ModelData::requiredString($data, 'code'),
            $message === null ? new LocalizedText() : LocalizedText::fromArray($message),
        );
    }

    /**
     * Writes the wire code, not the lenient enum, so an unknown code round-trips.
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['code' => $this->rawCode, 'message' => $this->message->jsonSerialize()];
    }
}
