<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Captures response bytes and fields exactly as received for signature verification. */
final readonly class VerifiableResponse
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * Stores raw response data before validation so the digest covers the bytes actually received.
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        #[\SensitiveParameter]
        array $headers,
        #[\SensitiveParameter]
        public string $body,
        #[\SensitiveParameter]
        public ?string $requestSignatureInput,
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $this->headers = $normalized;
    }

    /** Finds a response field without depending on the casing chosen by the HTTP client. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Omits raw response bytes from native diagnostic output. */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'requestSignatureInput' => $this->requestSignatureInput,
        ];
    }
}
