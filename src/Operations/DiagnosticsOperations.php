<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Models\SignatureDiagnostic;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Signing\SignatureProfile;

/** Checks the facts Anis reconstructed from a signed request. */
final class DiagnosticsOperations extends AbstractOperations
{
    /** @internal */
    public function __construct(private readonly PartnerTransport $transport) {}

    /** Sends the self-check's exact empty JSON object to exercise the full admission path. */
    public function checkSignature(): SignatureDiagnostic
    {
        $response = $this->transport->request('POST', '/v1/diagnostics/signature', 'v1/diagnostics/signature', SignatureProfile::BodylessNonceMutation, ContentDigest::EMPTY_OBJECT);
        try {
            return SignatureDiagnostic::fromArray($response->json);
        } catch (\Throwable) {
            throw new \Anis\Partners\Errors\MalformedResponseException('The verified diagnostic answer does not match the diagnostic model.');
        }
    }
}
