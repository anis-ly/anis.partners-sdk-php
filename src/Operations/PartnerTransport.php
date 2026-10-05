<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\ClientOptions;
use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Observability\AnisPartnersTelemetry;
use Anis\Partners\Observability\Log;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Signing\PartnerRequestSigner;
use Anis\Partners\Signing\RandomNonceFactory;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureInputs;
use Anis\Partners\Signing\SignatureProfile;
use Anis\Partners\Verification\PartnerResponseVerifier;
use Anis\Partners\Verification\VerifiableResponse;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/** Freezes, signs, sends, verifies, and only then decodes each partner response. */
final class PartnerTransport
{
    /** Connects the host HTTP factories and verifier to the same exact wire pipeline. */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ClientOptions $options,
        private readonly ?RequestSigner $signer,
        private readonly PartnerResponseVerifier $verifier,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Sends one signed request and parses only its verified JSON body. */
    public function request(string $method, string $template, string $path, ?SignatureProfile $profile, ?string $body = null, ?string $operationId = null, bool $enrollment = false): TransportResponse
    {
        $startedAt = microtime(true);
        $span = AnisPartnersTelemetry::startRequest($template, $method, $operationId);
        $statusCode = null;
        $errorType = null;
        $errorCode = null;
        try {
            $uri = rtrim($this->options->authority, '/') . '/' . ltrim($path, '/');
            $request = $this->requests->createRequest($method, $uri);
            $body ??= '';
            if ($body !== '' || ($profile !== null && $profile !== SignatureProfile::SafeRead)) {
                $request = $request->withBody($this->streams->createStream($body));
                if ($body !== '') {
                    $request = $request->withHeader('Content-Type', 'application/json');
                }
            }
            $language = $this->options->acceptLanguageHeader();
            if ($language !== null) {
                $request = $request->withHeader('Accept-Language', $language);
            }
            if ($enrollment) {
                if ($this->enrollmentToken === null) {
                    throw new \LogicException('An enrollment token is required for an enrollment request.');
                }
                $request = $request->withHeader('Authorization', 'Enrollment ' . $this->enrollmentToken);
            } elseif ($profile !== null) {
                if ($this->signer === null) {
                    throw new \LogicException('A request signer is required for signed partner routes.');
                }
                $nonce = $profile === SignatureProfile::SafeRead ? null : (new RandomNonceFactory())->create();
                $digest = $profile === SignatureProfile::SafeRead ? null : ContentDigest::of($body);
                $date = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                $requestUri = $request->getUri();
                $scheme = strtolower($requestUri->getScheme());
                $port = $requestUri->getPort();
                $omitPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
                $authority = strtolower($requestUri->getHost() . ($port === null || $omitPort ? '' : ':' . $port));
                $requestTarget = $requestUri->getPath() === '' ? '/' : $requestUri->getPath();
                $query = $requestUri->getQuery();
                $inputs = new SignatureInputs($method, $authority, $requestTarget, $query, $date, $digest, $nonce, $operationId);
                $created = $this->clock->now()->getTimestamp();
                $expires = $created + $this->options->signatureLifetimeSeconds;
                $signingStarted = microtime(true);
                $signed = (new PartnerRequestSigner($this->signer))->sign($profile, $inputs, $created, $expires);
                $request = $request->withHeader('X-Anis-Date', $date)
                    ->withHeader('Signature-Input', $signed->signatureInput)
                    ->withHeader('Signature', $signed->signature);
                if ($signed->nonce !== null) {
                    $request = $request->withHeader('Nonce', $signed->nonce);
                }
                if ($signed->contentDigest !== null) {
                    $request = $request->withHeader('Content-Digest', $signed->contentDigest);
                }
                if ($signed->idempotencyKey !== null) {
                    $request = $request->withHeader('Idempotency-Key', $signed->idempotencyKey);
                }
                Log::write($this->logger ?? new \Psr\Log\NullLogger(), 'debug', 'Anis request signed', 1000, ['method' => strtoupper($method), 'path' => $template, 'profile' => $profile->value, 'key_id' => $this->signer->keyId()]);
                AnisPartnersTelemetry::signatureDuration((microtime(true) - $signingStarted) * 1000, $profile->value);
            }

            $request = $request->withHeader('Accept-Encoding', 'identity');

            $started = microtime(true);
            $response = $this->http->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $bytes = (string) $response->getBody();
            $headers = [];
            $rawHeaders = [];
            foreach ($response->getHeaders() as $name => $values) {
                $stringValues = [];
                foreach ($values as $value) {
                    $stringValues[] = $value;
                }
                $headerName = (string) $name;
                $headers[$headerName] = implode(', ', $stringValues);
                $rawHeaders[$headerName] = $stringValues;
            }
            $sentSignatureInput = $request->getHeaderLine('Signature-Input');
            $this->verifier->verify(new VerifiableResponse($response->getStatusCode(), $headers, $bytes, $sentSignatureInput === '' ? null : $sentSignatureInput));
            $elapsed = (microtime(true) - $started) * 1000;
            AnisPartnersTelemetry::setAttribute($span, 'http.response.status_code', $statusCode);
            $requestId = $response->getHeaderLine('X-Request-Id');
            if ($requestId !== '') {
                AnisPartnersTelemetry::setAttribute($span, 'anis.request_id', $requestId);
            }
            Log::write($this->logger ?? new \Psr\Log\NullLogger(), 'debug', 'Anis response received', 1001, ['method' => strtoupper($method), 'route' => $template, 'status_code' => $response->getStatusCode(), 'elapsed_ms' => $elapsed, 'request_id' => $response->getHeaderLine('X-Request-Id')]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $error = AnisApiException::fromResponse($response->getStatusCode(), $rawHeaders, $bytes);
                AnisPartnersTelemetry::setAttribute($span, 'anis.error.code', $error->rawCode ?? $error->errorCode->value);
                $errorCode = $error->rawCode ?? $error->errorCode->value;
                Log::write($this->logger ?? new \Psr\Log\NullLogger(), 'warning', 'Anis refused request', 1002, ['method' => strtoupper($method), 'route' => $template, 'code' => $error->rawCode, 'status_code' => $error->status, 'request_id' => $error->requestId, 'retryable' => $error->isRetryable, 'replayed' => $error->isReplayed]);
                throw $error;
            }
            if ($bytes === '') {
                throw AnisApiException::emptyBody($response->getStatusCode());
            }
            try {
                $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw $exception;
            }
            if ($decoded === null) {
                throw AnisApiException::emptyBody($response->getStatusCode());
            }
            if (!is_array($decoded)) {
                throw new \UnexpectedValueException('A successful Anis response must be a JSON object.');
            }

            return new TransportResponse($response->getStatusCode(), $headers, $decoded, $bytes, $rawHeaders);
        } catch (\Throwable $exception) {
            if ($exception instanceof RequestSigningException) {
                $errorType = 'signing';
            } elseif ($exception instanceof \Anis\Partners\Verification\UnverifiableResponseException) {
                $errorType = 'unverifiable';
            } elseif ($exception instanceof \Psr\Http\Client\ClientExceptionInterface) {
                $errorType = 'connection';
                Log::write($this->logger ?? new \Psr\Log\NullLogger(), 'warning', 'Anis request ended without a usable answer', 1007, ['method' => strtoupper($method), 'route' => $template, 'reason' => $errorType]);
            } else {
                $errorType = 'other';
            }
            if ($exception instanceof AnisApiException) {
                $errorCode = $exception->rawCode ?? $exception->errorCode->value;
                if ($exception->problem->title === 'Empty body') {
                    $errorType = 'empty_body';
                }
            }
            AnisPartnersTelemetry::setStatus($span, StatusCode::STATUS_ERROR, $errorType);
            AnisPartnersTelemetry::setAttribute($span, 'error.type', $errorType);
            if ($exception instanceof AnisApiException) {
                AnisPartnersTelemetry::setAttribute($span, 'anis.error.code', $exception->rawCode ?? $exception->errorCode->value);
            }
            throw $exception;
        } finally {
            AnisPartnersTelemetry::requestDuration((microtime(true) - $startedAt) * 1000, $template, $method, $statusCode, $errorType, $errorCode);
            AnisPartnersTelemetry::end($span);
        }
    }

    private ?string $enrollmentToken = null;

    /** Attaches the one-use enrollment token for an unsigned request. */
    public function withEnrollmentToken(string $token): self
    {
        $copy = clone $this;
        $copy->enrollmentToken = $token;

        return $copy;
    }

    /** Hides the one-use enrollment token from object dumps while keeping it available to send. */
    public function __debugInfo(): array
    {
        return ['enrollment token' => $this->enrollmentToken === null ? null : '<redacted>'];
    }

}
