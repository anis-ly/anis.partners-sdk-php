<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Signs requests under the selected Partner profile and returns the exact fields to transmit. */
final class PartnerRequestSigner
{
    /**
     * Configures the credential that Anis uses to verify outgoing requests.
     *
     */
    public function __construct(private readonly RequestSigner $signer) {}

    /** Refuses incomplete or malformed signatures before any request can be sent. */
    public function sign(SignatureProfile $profile, #[\SensitiveParameter] SignatureInputs $inputs, int $created, int $expires): SignedRequestHeaders
    {
        if (($profile === SignatureProfile::SafeRead) !== ($inputs->nonce === null)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException($profile === SignatureProfile::SafeRead
                ? 'The SafeRead profile carries no nonce.'
                : 'The mutation profile requires a nonce.');
        }
        if ($expires - $created > PartnerRequestSignatureBase::MAX_SIGNATURE_LIFETIME_SECONDS) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('A request signature may live at most 300 seconds.');
        }

        try {
            $components = $profile->components();
            $keyId = $this->signer->keyId();
            $parameters = PartnerRequestSignatureBase::parameters($components, $created, $expires, $keyId, $inputs->nonce);
            $base = PartnerRequestSignatureBase::build($components, $inputs, $parameters);
            $bytes = $this->signer->sign($base);
        } catch (\Throwable $error) {
            throw new RequestSigningException($error);
        }
        if (strlen($bytes) !== 64) {
            throw new RequestSigningException(null, true);
        }

        return new SignedRequestHeaders(
            PartnerRequestSignatureBase::signatureInputHeader($parameters),
            PartnerRequestSignatureBase::signatureHeader($bytes),
            $inputs->anisDate,
            $inputs->contentDigest,
            $inputs->nonce,
            $inputs->idempotencyKey,
            $base,
        );
    }
}
