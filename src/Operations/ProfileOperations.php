<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Models\PartnerProfile;

/** Reads the calling application's identity and current effective scopes. */
final class ProfileOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Re-reads live policy so callers do not act on cached permissions. */
    public function get(): PartnerProfile
    {
        /** @var PartnerProfile */
        return $this->fetchModel($this->transport, '/v1/profile', 'v1/profile', [PartnerProfile::class, 'fromArray']);
    }
}
