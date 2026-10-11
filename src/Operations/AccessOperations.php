<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\ApplicationSuspended;
use Anis\Partners\Models\KeyRevoked;
use Anis\Partners\Models\ModelData;
use Anis\Partners\Signing\SignatureProfile;

/** Stops access for the calling application without requiring an application scope. */
final class AccessOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Suspends the calling application; staff must resume it. */
    public function suspendApplication(string $reason): ApplicationSuspended
    {
        $response = $this->transport->request('POST', '/v1/application/suspend', 'v1/application/suspend', SignatureProfile::BodylessNonceMutation, self::body($reason));
        try {
            return ApplicationSuspended::fromArray($response->json);
        } catch (\Throwable) {
            throw new \Anis\Partners\Errors\MalformedResponseException('The verified stop answer does not match the application model.');
        }
    }

    /** Revokes a key belonging to the calling application permanently. */
    public function revokeKey(string $keyId, string $reason): KeyRevoked
    {
        $path = 'v1/keys/' . Uuid::canonical($keyId, 'key id') . '/revoke';
        $response = $this->transport->request('POST', '/v1/keys/{keyId}/revoke', $path, SignatureProfile::BodylessNonceMutation, self::body($reason));
        try {
            return KeyRevoked::fromArray($response->json);
        } catch (\Throwable) {
            throw new \Anis\Partners\Errors\MalformedResponseException('The verified stop answer does not match the key model.');
        }
    }

    private static function body(string $reason): string
    {
        $reason = trim($reason);
        $count = preg_match_all('/./us', $reason);
        if ($count === false || $count < 1 || $count > 500) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The reason must contain 1 to 500 characters.');
        }
        return ModelData::json(['reason' => $reason]);
    }
}
