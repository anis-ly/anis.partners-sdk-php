<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Signing\SignatureProfile;

/**
 * Describes a published API route, the signing profile its request requires (when signed), and whether Anis signs its answers.
 *
 * A route with $signsResponse true has every answer, success and refusal, verified; a missing signature is refused.
 * A route with $signsResponse false is an information read whose answers Anis does not sign, so they are not verified.
 */
final readonly class PartnerRoute
{
    public function __construct(
        public string $method,
        public string $template,
        public ?SignatureProfile $profile,
        // Defaults to signed so a route built without the flag can never take the unverified path; the closed route
        // table in PartnerRoutes states it explicitly for every route.
        public bool $signsResponse = true,
        // The gateway's stable route id, the permission the route needs (null when it needs none) and whether the route
        // names a wallet. They describe the published surface and are compared with the contract by the drift tests.
        public ?string $routeId = null,
        public ?string $requiredScope = null,
        public bool $walletBound = false,
    ) {}
}
