<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Signing\SignatureProfile;

/** Describes a published API route and the signing profile it requires, when signed. */
final readonly class PartnerRoute
{
    public function __construct(
        public string $method,
        public string $template,
        public ?SignatureProfile $profile,
    ) {}
}
