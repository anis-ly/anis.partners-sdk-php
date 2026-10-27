<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Models\ApplicationSuspended;
use Anis\Partners\Models\KeyRevoked;
use Anis\Partners\Signing\ContentDigest;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccessOperationsTest extends TestCase
{
    private const KEY = '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c';

    #[Test]
    public function it_signs_json_reasons_with_nonce_and_verifies_both_stop_answers(): void
    {
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
        $wire->body = '{"keyId":"' . self::KEY . '","status":"revoked","revokedAt":"2026-10-11T09:00:00Z"}';
        $key = $client->access()->revokeKey(strtoupper(self::KEY), '  Custody incident  ');
        self::assertInstanceOf(KeyRevoked::class, $key);
        self::assertSame(self::KEY, $key->keyId);
        self::assertSame('2026-10-11T09:00:00Z', $key->revokedAt?->format('Y-m-d\TH:i:s\Z'));
        $wire->body = '{"applicationId":"' . self::KEY . '","status":"suspended","suspendedAt":"2026-10-11T09:00:00Z"}';
        $application = $client->access()->suspendApplication('  Maintenance  ');
        self::assertInstanceOf(ApplicationSuspended::class, $application);
        self::assertSame('suspended', $application->status);
        $requests = [$wire->requests[0], $wire->requests[2]];
        foreach ($requests as $index => $request) {
            self::assertSame('POST', $request->getMethod());
            self::assertSame($index === 0 ? '/v1/keys/' . self::KEY . '/revoke' : '/v1/application/suspend', $request->getUri()->getPath());
            $body = $index === 0 ? '{"reason":"Custody incident"}' : '{"reason":"Maintenance"}';
            self::assertSame($body, (string) $request->getBody());
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
            self::assertSame(ContentDigest::of($body), $request->getHeaderLine('Content-Digest'));
            self::assertNotSame('', $request->getHeaderLine('Nonce'));
            self::assertSame('', $request->getHeaderLine('Idempotency-Key'));
            self::assertStringStartsWith('sig1=("@method" "@authority" "@path" "@query" "content-digest" "nonce" "x-anis-date")', $request->getHeaderLine('Signature-Input'));
            self::assertSame('anis-partners-php/2.2.0', $request->getHeaderLine('User-Agent'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReasons(): iterable
    {
        yield 'blank' => ['  '];
        yield 'too long' => [str_repeat('a', 501)];
        yield 'too many unicode characters' => [str_repeat('ع', 501)];
        yield 'invalid utf8' => ["\xff"];
    }

    #[Test]
    #[DataProvider('invalidReasons')]
    public function it_refuses_invalid_reasons_before_sending(string $reason): void
    {
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
        foreach (['suspend', 'revoke'] as $operation) {
            try {
                if ($operation === 'suspend') {
                    $client->access()->suspendApplication($reason);
                } else {
                    $client->access()->revokeKey(self::KEY, $reason);
                }
                self::fail('Invalid reasons cannot be sent.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $wire->requests);
            }
        }
    }
}
