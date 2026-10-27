<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Internal\SystemClock;
use Anis\Partners\Models\WebhookEnvelope;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Psr\Clock\ClockInterface;

/**
 * Proves that a received webhook was sent by Anis for this application and address, with the body unchanged.
 *
 * The address and application id the delivery must match come only from the constructor. Nothing in the request,
 * including the Host or forwarding headers, decides what is expected. Read the raw body bytes before any JSON handling
 * and pass them in unchanged. Deduplicate on the event id after verification and before acting on the delivery.
 */
final class PartnerWebhookVerifier
{
    private readonly string $applicationId;
    private readonly ClockInterface $clock;

    /**
     * @param SigningKeySource $keys The Anis key document source, such as HttpSigningKeySource for your Anis authority.
     * @param string $configuredUrl The full HTTPS address Anis was told to deliver to, including any query.
     * @param string $applicationId The application id of this integration.
     */
    public function __construct(
        private readonly SigningKeySource $keys,
        private readonly string $configuredUrl,
        string $applicationId,
        ?ClockInterface $clock = null,
    ) {
        $this->applicationId = \Anis\Partners\Internal\Uuid::canonical($applicationId, 'application id');
        $this->clock = $clock ?? new SystemClock();
    }

    /** Throws a reason-only refusal unless the delivery is authentic, fresh, addressed to this receiver and unchanged. */
    public function verify(#[\SensitiveParameter] VerifiableWebhook $webhook): void
    {
        $this->check($webhook);
    }

    /** Verifies, then reads the envelope; nothing is parsed from a delivery that failed verification. */
    public function verifyAndParse(#[\SensitiveParameter] VerifiableWebhook $webhook): WebhookEnvelope
    {
        [$webhookId, $application] = $this->check($webhook);

        return WebhookEnvelopeReader::read($webhook->body, $webhookId, $application);
    }

    /** @return array{string, string} The verified webhook id and application header values. */
    private function check(#[\SensitiveParameter] VerifiableWebhook $webhook): array
    {
        // 1. Presence, syntax, label, algorithm and profile.
        $signatures = $webhook->headerValues('Signature');
        $inputs = $webhook->headerValues('Signature-Input');
        if ($signatures === [] || $inputs === [] || $signatures[0] === '' || $inputs[0] === '') {
            self::fail(WebhookVerificationFailure::SignatureMissing);
        }
        if (count($signatures) !== 1 || count($inputs) !== 1) {
            self::fail(WebhookVerificationFailure::SignatureMalformed);
        }
        $parsed = WebhookSignatureInputParser::tryParse($inputs[0]);
        $signature = WebhookSignatureInputParser::tryParseSignature($signatures[0]);
        if ($parsed === null || $signature === null || $parsed['created'] === null || $parsed['keyId'] === null || $parsed['algorithm'] === null) {
            self::fail(WebhookVerificationFailure::SignatureMalformed);
        }
        if ($parsed['label'] !== PartnerWebhookSignatureBase::LABEL || $signature['label'] !== PartnerWebhookSignatureBase::LABEL) {
            self::fail(WebhookVerificationFailure::LabelUnexpected);
        }
        if ($parsed['algorithm'] !== PartnerWebhookSignatureBase::ALGORITHM) {
            self::fail(WebhookVerificationFailure::AlgorithmNotSupported);
        }
        if ($parsed['tag'] !== PartnerWebhookSignatureBase::PROFILE) {
            self::fail(WebhookVerificationFailure::ProfileUnexpected);
        }

        // 2. The digest of the raw bytes, compared in constant time.
        $digests = $webhook->headerValues('Content-Digest');
        $computed = 'sha-256=:' . base64_encode(hash('sha256', $webhook->body, true)) . ':';
        if (count($digests) !== 1 || !hash_equals($computed, $digests[0])) {
            self::fail(WebhookVerificationFailure::ContentDigestMismatch);
        }

        // 3. The exact covered list and the binding headers.
        if ($parsed['components'] !== PartnerWebhookSignatureBase::COMPONENTS) {
            self::fail(WebhookVerificationFailure::CoveredComponentsMismatch);
        }
        $webhookIds = $webhook->headerValues('webhook-id');
        $attempts = $webhook->headerValues('anis-webhook-attempt');
        $applications = $webhook->headerValues('anis-application-id');
        if (count($webhookIds) !== 1 || count($attempts) !== 1 || count($applications) !== 1 || $webhookIds[0] === '' || $applications[0] === '') {
            self::fail(WebhookVerificationFailure::CoveredComponentsMismatch);
        }
        if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $attempts[0]) !== 1 || (int) $attempts[0] > PartnerWebhookSignatureBase::MAX_ATTEMPT) {
            self::fail(WebhookVerificationFailure::CoveredComponentsMismatch);
        }

        // 4. Lifetime, then expiry, then clock skew.
        $created = $parsed['created'];
        $expires = $parsed['expires'];
        if ($expires === null || $expires - $created !== PartnerWebhookSignatureBase::LIFETIME_SECONDS) {
            self::fail(WebhookVerificationFailure::ValidityWindowInvalid);
        }
        $now = $this->clock->now()->getTimestamp();
        if ($now > $expires) {
            self::fail(WebhookVerificationFailure::SignatureExpired);
        }
        if (abs($now - $created) > PartnerWebhookSignatureBase::SKEW_SECONDS) {
            self::fail(WebhookVerificationFailure::CreatedOutOfWindow);
        }

        // 5. The receiver that was actually reached.
        if ($webhook->method !== 'POST') {
            self::fail(WebhookVerificationFailure::MethodUnexpected);
        }
        if ($webhook->targetUri !== $this->configuredUrl || $this->configuredUrl === '') {
            self::fail(WebhookVerificationFailure::TargetUriMismatch);
        }
        if (strtolower($applications[0]) !== $this->applicationId) {
            self::fail(WebhookVerificationFailure::ApplicationIdMismatch);
        }

        // 6. The key.
        $key = $this->key($parsed['keyId'], $created);

        // 7. The signature.
        $bytes = base64_decode($signature['base64'], true);
        if ($bytes === false || base64_encode($bytes) !== $signature['base64'] || strlen($bytes) !== 64) {
            self::fail(WebhookVerificationFailure::SignatureMalformed);
        }
        $x = self::coordinate($key->x);
        $y = self::coordinate($key->y);
        if ($x === null || $y === null) {
            self::fail(WebhookVerificationFailure::KeyRejected);
        }
        $base = PartnerWebhookSignatureBase::build($webhook->targetUri, $digests[0], $webhookIds[0], $attempts[0], $applications[0], $parsed['parameters']);
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        $publicKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
        if ($publicKey === false) {
            self::clearOpenSslErrors();
            self::fail(WebhookVerificationFailure::KeyRejected);
        }
        $verified = openssl_verify($base, EcdsaSignatureFormat::p1363ToDer($bytes), $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            self::clearOpenSslErrors();
            self::fail(WebhookVerificationFailure::SignatureInvalid);
        }

        return [$webhookIds[0], $applications[0]];
    }

    private function key(string $keyId, int $created): PartnerJwk
    {
        $document = $this->keys->get();
        $matches = self::matching($document, $keyId);
        if ($matches === [] && !self::isRevoked($document, $keyId)) {
            $document = $this->keys->refresh();
            $matches = self::matching($document, $keyId);
        }
        if ($matches === []) {
            self::fail(self::isRevoked($document, $keyId) ? WebhookVerificationFailure::KeyCompromised : WebhookVerificationFailure::UnknownKey);
        }
        foreach ($document->keys as $candidate) {
            if ($candidate->hasPrivateMember) {
                self::fail(WebhookVerificationFailure::KeyRejected);
            }
        }
        $key = $matches[0];
        if (count($matches) !== 1 || $key->kty !== 'EC' || $key->crv !== 'P-256' || $key->notBefore === null
            || !in_array($key->status, ['active', 'next', 'retired'], true) || ($key->status === 'retired' && $key->retiredAt === null)
            || self::coordinate($key->x) === null || self::coordinate($key->y) === null) {
            self::fail(WebhookVerificationFailure::KeyRejected);
        }
        if (self::isRevoked($document, $keyId)) {
            self::fail(WebhookVerificationFailure::KeyCompromised);
        }
        if ($key->status === 'retired') {
            self::fail(WebhookVerificationFailure::KeyRetired);
        }
        $notBefore = $key->notBefore->getTimestamp() + ($key->notBefore->format('u') === '000000' ? 0 : 1);
        if ($created < $notBefore) {
            self::fail(WebhookVerificationFailure::KeyNotYetValid);
        }
        if ($key->purpose !== 'webhook') {
            self::fail(WebhookVerificationFailure::KeyPurposeMismatch);
        }

        return $key;
    }

    /** @return list<PartnerJwk> */
    private static function matching(SigningKeySet $document, string $keyId): array
    {
        return array_values(array_filter($document->keys, static fn(PartnerJwk $key): bool => $key->kid === $keyId));
    }

    private static function isRevoked(SigningKeySet $document, string $keyId): bool
    {
        foreach ($document->revoked as $revoked) {
            if ($revoked->kid === $keyId) {
                return true;
            }
        }

        return false;
    }

    private static function coordinate(#[\SensitiveParameter] ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = Base64Url::decode($value);

        return $decoded !== null && strlen($decoded) === 32 ? $decoded : null;
    }

    private static function fail(WebhookVerificationFailure $failure): never
    {
        throw new UnverifiableWebhookException($failure);
    }

    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
