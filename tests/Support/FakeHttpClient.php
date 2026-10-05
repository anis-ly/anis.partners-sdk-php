<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public int $calls = 0;
    public ?RequestInterface $lastRequest = null;
    public ?\Throwable $failure = null;

    public function __construct(public string $body = '{"keys":[]}') {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->lastRequest = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response(200, [], $this->body);
    }
}
