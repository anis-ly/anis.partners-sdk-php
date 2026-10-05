<?php

declare(strict_types=1);

namespace Anis\Partners\Enrollment;

use Anis\Partners\AcceptLanguage;
use Anis\Partners\ClientOptions;
use Anis\Partners\Internal\Uuid;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Models\EnrollmentKeyResult;
use Anis\Partners\Models\EnrollmentProofRequest;
use Anis\Partners\Models\EnrollmentState;
use Anis\Partners\Models\EnrollmentStatus;
use Anis\Partners\Operations\PartnerTransport;
use Anis\Partners\Signing\P256Signer;
use Anis\Partners\Verification\HttpSigningKeySource;
use Anis\Partners\Verification\PartnerResponseVerifier;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/** Bootstraps an application credential before normal signed API calls are available. */
final class EnrollmentClient
{
    private function __construct(private readonly PartnerTransport $transport, private readonly string $invitationId) {}

    /** Creates the enrollment pipeline; its token is sent only in Authorization and never logged. */
    public static function create(
        string $authority,
        string $invitationId,
        string $enrollmentToken,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        ?CacheInterface $keyCache = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ): self {
        $invitationId = Uuid::canonical($invitationId);
        if (trim($enrollmentToken) === '') {
            throw new \InvalidArgumentException('An enrollment token is required.');
        }
        $http ??= Psr18ClientDiscovery::find();
        $requests ??= Psr17FactoryDiscovery::findRequestFactory();
        $streams ??= Psr17FactoryDiscovery::findStreamFactory();
        $clock ??= new \Anis\Partners\Internal\SystemClock();
        $options = new ClientOptions($authority, 60, AcceptLanguage::Unspecified, 600);
        $keys = new HttpSigningKeySource($http, $requests, $authority, 600, $keyCache, $clock, $logger);
        $transport = (new PartnerTransport($http, $requests, $streams, $options, null, new PartnerResponseVerifier($keys, $clock, $logger), $clock, $logger))
            ->withEnrollmentToken($enrollmentToken);

        return new self($transport, $invitationId);
    }

    /** Reads the invitation before a key is submitted. */
    public function get(): EnrollmentState
    {
        return EnrollmentState::fromArray($this->transport->request('GET', '/v1/enrollments/{invitationId}', 'v1/enrollments/' . $this->invitationId, null, enrollment: true)->json);
    }

    /** Reads enrollment approval and key expiry state, including omitted early-step values. */
    public function getStatus(): EnrollmentStatus
    {
        return EnrollmentStatus::fromArray($this->transport->request('GET', '/v1/enrollments/{invitationId}/status', 'v1/enrollments/' . $this->invitationId . '/status', null, enrollment: true)->json);
    }

    /** Submits a public key and returns a locally derived safety code only after thumbprints match. */
    public function submitKey(EnrollmentKeyRequest $request): EnrollmentKeyResult
    {
        $local = KeyThumbprint::compute($request->publicJwk);
        $answer = $this->transport->request('POST', '/v1/enrollments/{invitationId}/keys', 'v1/enrollments/' . $this->invitationId . '/keys', null, $request->toJson(), enrollment: true);
        $result = EnrollmentKeyResult::fromArray($answer->json);
        if ($result->thumbprint === null || !hash_equals($local, $result->thumbprint)) {
            throw new EnrollmentKeyMismatchException($local, $result->thumbprint);
        }

        return new EnrollmentKeyResult($result->keyId, $result->thumbprint, SafetyCode::fromThumbprint($local), $result->challenge, $result->challengeGeneration);
    }

    /** Sends the proof request after Anis has verified it. */
    public function submitProof(EnrollmentProofRequest $request): EnrollmentStatus
    {
        return EnrollmentStatus::fromArray($this->transport->request('POST', '/v1/enrollments/{invitationId}/proof', 'v1/enrollments/' . $this->invitationId . '/proof', null, $request->toJson(), enrollment: true)->json);
    }

    /** Builds and submits proof of possession for the submitted public key. */
    public function prove(EnrollmentKeyResult $submitted, P256Signer $signer): EnrollmentStatus
    {
        if ($submitted->challenge === null || $submitted->thumbprint === null || $submitted->challengeGeneration === null) {
            throw new \InvalidArgumentException('The submitted key result must include its challenge, thumbprint, and generation.');
        }
        $message = EnrollmentProof::message($submitted->keyId, $submitted->challengeGeneration, $submitted->challenge, $submitted->thumbprint);
        $request = new EnrollmentProofRequest($submitted->keyId, $submitted->challengeGeneration, EnrollmentProof::signature($message, $signer));

        return $this->submitProof($request);
    }
}
