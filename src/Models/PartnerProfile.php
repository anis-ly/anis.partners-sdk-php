<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Describes the authenticated application and scopes currently returned by Anis. */
final readonly class PartnerProfile implements \JsonSerializable
{
    use WireJsonSerialization;

    public function __construct(
        public ?PartnerIdentity $partner = null,
        public ?ApplicationIdentity $application = null,
        public ?OwnerAccount $ownerAccount = null,
        public ?string $documentationVersion = null,
    ) {}

    /**
     * Reads optional identity sections without implying that omitted sections have values.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $partner = ModelData::object($data, 'partner');
        $application = ModelData::object($data, 'application');
        $ownerAccount = ModelData::object($data, 'ownerAccount');

        return new self(
            $partner === null ? null : PartnerIdentity::fromArray($partner),
            $application === null ? null : ApplicationIdentity::fromArray($application),
            $ownerAccount === null ? null : OwnerAccount::fromArray($ownerAccount),
            ModelData::nullableString($data, 'documentationVersion'),
        );
    }
}
