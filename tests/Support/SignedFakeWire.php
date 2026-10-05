<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use Anis\Partners\Signing\EcdsaSignatureFormat;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Verification\PartnerResponseSignatureBase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Records outgoing requests and signs each scripted response with a trusted test key. */
final class SignedFakeWire implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    public int $status = 200;
    public string $body = '{}';
    /** @var array<string, string|list<string>> */
    public array $responseHeaders = ['X-Request-Id' => 'req-test'];
    /** @var list<array{status: int, body: string, headers: array<string, string|list<string>>}> */
    public array $responseQueue = [];
    public bool $tamperAfterSigning = false;
    public ?\Throwable $failure = null;

    private readonly PemP256Signer $responseSigner;
    private readonly \OpenSSLAsymmetricKey $privateKey;
    private readonly string $keyId;
    private readonly RequestSigner $requestSigner;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem) || !is_string($pem)) {
            throw new \RuntimeException('Could not create the response test key.');
        }
        $this->privateKey = $key;
        $this->responseSigner = PemP256Signer::fromPem($pem);
        $this->requestSigner = $this->responseSigner->forKey('8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48');
        $this->keyId = 'partner-response-signing/v1-active';
    }

    /** Sends a request to the script and preserves it for assertions about exact wire bytes. */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if (str_ends_with((string) $request->getUri(), '/.well-known/partner-signing-keys.json')) {
            $jwk = $this->responseSigner->publicJwk()->toArray();
            $jwk['kid'] = $this->keyId;
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR));
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $script = array_shift($this->responseQueue);
        $body = $script['body'] ?? $this->body;
        $status = $script['status'] ?? $this->status;
        $headers = $script['headers'] ?? $this->responseHeaders;
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
        $headers['Content-Digest'] = $digest;
        $requestSignatureInput = $request->getHeaderLine('Signature-Input');
        $components = PartnerResponseSignatureBase::components(
            $status,
            $digest,
            self::headerLine($headers['X-Request-Id'] ?? 'req-test') ?? 'req-test',
            $requestSignatureInput !== '' ? $requestSignatureInput : null,
            self::headerLine($headers['Location'] ?? null),
            self::headerLine($headers['Retry-After'] ?? null),
            self::headerLine($headers['Idempotency-Replayed'] ?? null),
            self::headerLine($headers['Cache-Control'] ?? null),
        );
        $created = time();
        $signatureInput = PartnerResponseSignatureBase::signatureInputHeader($components, $created, $this->keyId);
        $base = PartnerResponseSignatureBase::build($components, $created, $this->keyId);
        if (!openssl_sign($base, $der, $this->privateKey, OPENSSL_ALGO_SHA256) || !is_string($der)) {
            throw new \RuntimeException('Could not sign the response test body.');
        }
        $headers['Signature-Input'] = $signatureInput;
        $headers['Signature'] = 'sig1=:' . base64_encode(EcdsaSignatureFormat::derToP1363($der)) . ':';
        if ($this->tamperAfterSigning) {
            $body .= ' ';
        }

        return new Response($status, $headers, $body);
    }

    /** Supplies a real P-256 signer for signed client requests in pipeline tests. */
    public function requestSigner(): RequestSigner
    {
        return $this->requestSigner;
    }

    /** @param string|list<string>|null $values */
    private static function headerLine(string|array|null $values): ?string
    {
        return $values === null ? null : (is_array($values) ? implode(', ', $values) : $values);
    }

}
