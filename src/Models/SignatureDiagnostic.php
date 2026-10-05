<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Shows the request facts Anis used to diagnose a signature mismatch. */
final readonly class SignatureDiagnostic implements \JsonSerializable
{
    use WireJsonSerialization;
    /**
     * @param list<string> $coveredComponents
     * @param list<string> $effectiveScopes
     */
    public function __construct(
        public ?string $routeId = null,
        public ?string $method = null,
        public ?string $authority = null,
        public ?string $path = null,
        public ?string $canonicalQuery = null,
        public ?string $requestKind = null,
        public ?string $requiredScope = null,
        public array $coveredComponents = [],
        public ?string $keyId = null,
        public ?string $partnerId = null,
        public ?string $applicationId = null,
        public ?int $policyVersion = null,
        public array $effectiveScopes = [],
        public ?\DateTimeImmutable $receivedAt = null,
    ) {}

    /**
     * Reads the diagnostic's optional facts without losing the route's declared component order.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ModelData::nullableString($data, 'routeId'),
            ModelData::nullableString($data, 'method'),
            ModelData::nullableString($data, 'authority'),
            ModelData::nullableString($data, 'path'),
            ModelData::nullableString($data, 'canonicalQuery'),
            ModelData::nullableString($data, 'requestKind'),
            ModelData::nullableString($data, 'requiredScope'),
            ModelData::stringList($data, 'coveredComponents'),
            ModelData::nullableUuid($data, 'keyId'),
            ModelData::nullableUuid($data, 'partnerId'),
            ModelData::nullableUuid($data, 'applicationId'),
            ModelData::nullableInteger($data, 'policyVersion'),
            ModelData::stringList($data, 'effectiveScopes'),
            ModelData::dateTime($data, 'receivedAt'),
        );
    }
}
