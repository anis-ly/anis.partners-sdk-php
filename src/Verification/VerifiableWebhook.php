<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Captures a received webhook delivery exactly as it arrived: the real method, target address, headers and raw body bytes. */
final readonly class VerifiableWebhook
{
    /** @var array<string, string|list<string>> */
    public array $headers;

    /**
     * @param string $method The HTTP method of the received request.
     * @param string $targetUri The absolute address the request was received on, including its query as sent.
     * @param array<string, string|list<string>> $headers The received headers; a repeated header may appear as several values or under keys that differ only in case.
     * @param string $body The raw request body bytes, read before any JSON handling.
     */
    public function __construct(
        public string $method,
        public string $targetUri,
        #[\SensitiveParameter]
        array $headers,
        #[\SensitiveParameter]
        public string $body,
    ) {
        $this->headers = $headers;
    }

    /**
     * Returns every value received under a header name, whatever the casing, so a repeated header is never collapsed.
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        $values = [];
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                $values[] = $item;
            }
        }

        return $values;
    }

    /** Keeps raw bytes and header values out of dumps and logs. */
    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'targetUri' => $this->targetUri, 'headers' => '<redacted>', 'body' => '<redacted>'];
    }
}
