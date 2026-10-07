<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\MaskedCard;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\RevealedCredentialCollection;
use Anis\Partners\Models\SignatureDiagnostic;
use Anis\Partners\Signing\EcdsaSignatureFormat;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\SignatureProfile;
use Anis\Partners\Tests\Support\FixedNonceFactory;
use Anis\Partners\Tests\Support\SignedFakeWire;
use Anis\Partners\Verification\PartnerJwk;
use Anis\Partners\Verification\UnverifiableResponseException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartnerPipelineTest extends TestCase
{
    private const WALLET = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
    private const OPERATION = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
    private const CARD = '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046';

    #[Test]
    public function it_signs_a_safe_read_without_nonce_or_content_digest(): void
    {
        $wire = new SignedFakeWire();
        $wire->body = '{}';
        $client = self::client($wire);
        $client->profile()->get();
        $request = $wire->requests[0];

        self::assertSame(SignatureProfile::SafeRead->components(), self::components($request->getHeaderLine('Signature-Input')));
        self::assertSame('', $request->getHeaderLine('Nonce'));
        self::assertSame('', $request->getHeaderLine('Content-Digest'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame('identity', $request->getHeaderLine('Accept-Encoding'));
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $request->getHeaderLine('X-Anis-Date'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function it_signs_the_constructed_https_request_authority_without_its_default_port(): void
    {
        $wire = new SignedFakeWire();
        $capture = new class implements RequestSigner {
            public string $base = '';
            public function keyId(): string
            {
                return '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';
            }
            public function sign(string $data): string
            {
                $this->base = $data;
                return str_repeat("\0", 64);
            }
        };
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(new ClientOptions('https://unit.invalid:443'), $capture, $wire, $factory, $factory);
        $client->profile()->get();

        self::assertStringContainsString('"@authority": unit.invalid', $capture->base);
        self::assertStringNotContainsString('unit.invalid:443', $capture->base);
    }

    #[Test]
    public function it_does_not_follow_a_signed_redirect_answer(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 302;
        $wire->body = '{"status":302,"code":"dependency_unavailable"}';
        $wire->responseHeaders['Location'] = 'https://other.invalid/redirected';

        try {
            self::client($wire)->orders()->get(self::OPERATION);
            self::fail('The 302 response should be surfaced as an ordinary verified refusal.');
        } catch (\Anis\Partners\Errors\AnisApiException) {
            self::assertCount(2, $wire->requests);
            self::assertSame('identity', $wire->requests[1]->getHeaderLine('Accept-Encoding'));
        }
    }

    #[Test]
    public function it_sends_a_reveal_with_no_body_bytes_and_a_digest_of_empty_bytes(): void
    {
        $wire = new SignedFakeWire();
        $wire->body = '{"soldCardId":"' . self::CARD . '","voucher":"code"}';
        $client = self::client($wire);
        $credential = $client->ownedCards()->reveal(self::WALLET, self::CARD);

        self::assertInstanceOf(RevealedCredential::class, $credential);
        self::assertSame('', (string) $wire->requests[0]->getBody());
        self::assertNotSame('', $wire->requests[0]->getHeaderLine('Nonce'));
        self::assertSame('sha-256=:' . base64_encode(hash('sha256', '', true)) . ':', $wire->requests[0]->getHeaderLine('Content-Digest'));
        self::assertSame(['@method', '@authority', '@path', '@query', 'content-digest', 'nonce', 'x-anis-date'], self::components($wire->requests[0]->getHeaderLine('Signature-Input')));
    }

    #[Test]
    public function it_uses_the_injected_nonce_factory_for_mutations(): void
    {
        $wire = new SignedFakeWire();
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(
            new ClientOptions('https://partners.example'),
            $wire->requestSigner(),
            $wire,
            $factory,
            $factory,
            nonceFactory: new FixedNonceFactory('fixed-nonce'),
        );
        $client->ownedCards()->reveal(self::WALLET, self::CARD);

        self::assertSame('fixed-nonce', $wire->requests[0]->getHeaderLine('Nonce'));
    }

    #[Test]
    public function it_sends_an_invoice_reveal_with_no_body_bytes(): void
    {
        $wire = new SignedFakeWire();
        $wire->body = '{"items":[]}';
        $client = self::client($wire);
        self::assertInstanceOf(RevealedCredentialCollection::class, $client->ownedCards()->revealInvoice(self::WALLET, self::CARD));
        self::assertSame('', (string) $wire->requests[0]->getBody());
        self::assertNotSame('', $wire->requests[0]->getHeaderLine('Nonce'));
        self::assertSame('POST', $wire->requests[0]->getMethod());
    }

    #[Test]
    public function it_sends_the_signature_check_as_an_empty_json_object(): void
    {
        $wire = new SignedFakeWire();
        $client = self::client($wire);
        self::assertInstanceOf(SignatureDiagnostic::class, $client->diagnostics()->checkSignature());
        self::assertSame('{}', (string) $wire->requests[0]->getBody());
        self::assertSame('application/json', $wire->requests[0]->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function it_sends_the_caller_operation_id_with_the_exact_order_json(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed","soldCards":[{"soldCardId":"' . self::CARD . '","voucher":"code"}]}';
        $client = self::client($wire);
        $result = $client->orders()->create(self::WALLET, self::OPERATION, self::order());

        self::assertInstanceOf(OrderCompleted::class, $result);
        self::assertSame(self::OPERATION, $wire->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame('{"cardId":"' . self::CARD . '","quantity":2,"expectedUnitPrice":{"amount":"10.500","currency":"LYD"},"expectedTotal":{"amount":"21.000","currency":"LYD"},"useAllowedDebt":false}', (string) $wire->requests[0]->getBody());
    }

    #[Test]
    public function it_signs_the_exact_order_request_bytes_received_by_the_http_client(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed"}';
        self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order());

        $request = $wire->requests[0];
        $signatureInput = $request->getHeaderLine('Signature-Input');
        $inputParts = [];
        if (preg_match('/\\Asig1=\\(([^)]*)\\)(;.*)\\z/', $signatureInput, $inputParts) !== 1) {
            throw new \UnexpectedValueException('The request signature input is malformed.');
        }
        preg_match_all('/"([^"]+)"/', $inputParts[1], $componentMatches);
        $components = $componentMatches[1];
        $uri = $request->getUri();
        $port = $uri->getPort();
        $scheme = strtolower($uri->getScheme());
        $authority = strtolower($uri->getHost() . ((($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) || $port === null) ? '' : ':' . $port));
        $values = [
            '@method' => strtoupper($request->getMethod()),
            '@authority' => $authority,
            '@path' => $uri->getPath() === '' ? '/' : $uri->getPath(),
            '@query' => $uri->getQuery() === '' ? '?' : '?' . $uri->getQuery(),
            'content-digest' => $request->getHeaderLine('Content-Digest'),
            'nonce' => $request->getHeaderLine('Nonce'),
            'idempotency-key' => $request->getHeaderLine('Idempotency-Key'),
            'x-anis-date' => $request->getHeaderLine('X-Anis-Date'),
        ];
        $base = '';
        foreach ($components as $component) {
            $base .= '"' . $component . '": ' . $values[$component] . "\n";
        }
        $parameters = substr($signatureInput, strlen('sig1='));
        $base .= '"@signature-params": ' . $parameters;

        self::assertSame('sha-256=:' . base64_encode(hash('sha256', (string) $request->getBody(), true)) . ':', $request->getHeaderLine('Content-Digest'));
        self::assertStringContainsString(';nonce="' . $request->getHeaderLine('Nonce') . '"', $parameters);
        self::assertMatchesRegularExpression('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z\\z/', $request->getHeaderLine('X-Anis-Date'));
        self::assertSame(strtolower(self::OPERATION), $request->getHeaderLine('Idempotency-Key'));

        $jwk = $wire->requestPublicJwk();
        self::assertInstanceOf(PartnerJwk::class, $jwk);
        $point = "\x04" . Base64Url::decode((string) $jwk->x) . Base64Url::decode((string) $jwk->y);
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        self::assertNotFalse($spki);
        $publicKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
        self::assertNotFalse($publicKey);
        $signatureMatch = [];
        if (preg_match('/\\Asig1=:([^:]+):\\z/', $request->getHeaderLine('Signature'), $signatureMatch) !== 1) {
            throw new \UnexpectedValueException('The request signature is malformed.');
        }
        $signature = base64_decode($signatureMatch[1], true);
        self::assertIsString($signature);
        self::assertSame(1, openssl_verify($base, EcdsaSignatureFormat::p1363ToDer($signature), $publicKey, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function it_refuses_to_return_a_body_changed_after_signing(): void
    {
        $wire = new SignedFakeWire();
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed"}';
        $wire->tamperAfterSigning = true;
        $client = self::client($wire);

        $this->expectException(UnverifiableResponseException::class);
        $client->orders()->get(self::OPERATION);
    }

    #[Test]
    public function it_hides_a_tampered_credential_response_from_exception_traces(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $wire = new SignedFakeWire();
        $wire->body = '{"soldCardId":"' . self::CARD . '","voucher":"trace-tampered-voucher"}';
        $wire->tamperAfterSigning = true;

        try {
            try {
                self::client($wire)->ownedCards()->reveal(self::WALLET, self::CARD);
                self::fail('A response whose credential body changed after signing must be rejected.');
            } catch (UnverifiableResponseException $exception) {
                self::assertStringNotContainsString('trace-tampered-voucher', print_r($exception->getTrace(), true));
            }
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    #[Test]
    public function it_follows_every_page_and_signs_the_encoded_cursor_it_sends(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26"}],"nextCursor":"page / 2"}', 'headers' => ['X-Request-Id' => 'req-1']],
            ['status' => 200, 'body' => '{"items":[{"id":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34"}],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'req-2']],
        ];
        $factory = new HttpFactory();
        $capturingSigner = new class ($wire->requestSigner()) implements RequestSigner {
            /** @var list<string> */
            public array $bases = [];

            public function __construct(private readonly RequestSigner $inner) {}

            public function keyId(): string
            {
                return $this->inner->keyId();
            }

            public function sign(#[\SensitiveParameter] string $data): string
            {
                $this->bases[] = $data;

                return $this->inner->sign($data);
            }
        };
        $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $capturingSigner, $wire, $factory, $factory);
        self::assertSame(['2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34'], array_map(static fn(mixed $item): string => $item instanceof \Anis\Partners\Models\Wallet ? $item->id : throw new \UnexpectedValueException(), iterator_to_array($client->wallets()->list())));

        self::assertCount(2, $wire->requests); // An information read is not verified, so no key-document request.
        self::assertSame('', $wire->requests[0]->getUri()->getQuery());
        self::assertSame('cursor=page%20%2F%202', $wire->requests[1]->getUri()->getQuery());
        self::assertStringContainsString('"@query": ?', $capturingSigner->bases[0]);
        self::assertStringContainsString('"@query": ?cursor=page%20%2F%202', $capturingSigner->bases[1]);
    }

    #[Test]
    public function it_walks_owned_card_pages_until_the_cursor_is_empty(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26"}],"nextCursor":"owned-2"}', 'headers' => ['X-Request-Id' => 'owned-1']],
            ['status' => 200, 'body' => '{"items":[{"id":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34"}],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'owned-2']],
        ];
        self::assertSame(['2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34'], array_map(static fn(mixed $item): string => $item instanceof MaskedCard ? $item->id : throw new \UnexpectedValueException(), iterator_to_array(self::client($wire)->ownedCards()->list(self::WALLET))));

        self::assertStringContainsString('/v1/wallets/' . self::WALLET . '/cards?cursor=owned-2', (string) $wire->requests[1]->getUri());
    }

    #[Test]
    public function it_stops_when_a_cursor_repeats_after_other_cursors(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"A"}', 'headers' => ['X-Request-Id' => 'cycle-1']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"B"}', 'headers' => ['X-Request-Id' => 'cycle-2']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"A"}', 'headers' => ['X-Request-Id' => 'cycle-3']],
        ];

        try {
            iterator_to_array(self::client($wire)->wallets()->list());
            self::fail('A→B→A cursor cycle must stop pagination.');
        } catch (\Anis\Partners\Errors\MalformedResponseException $exception) {
            self::assertSame('Anis repeated a paging cursor.', $exception->getMessage());
            self::assertCount(3, $wire->requests); // Three pages; an information read fetches no key document.
        }
    }

    #[Test]
    public function it_maps_malformed_reveal_and_enrollment_answers_to_the_common_exception(): void
    {
        $factory = new HttpFactory();
        $revealWire = new SignedFakeWire();
        $revealWire->body = '{"soldCardId":17,"voucher":"private-answer"}';
        try {
            self::client($revealWire)->ownedCards()->reveal(self::WALLET, self::CARD);
            self::fail('A malformed revealed credential must be refused as a malformed response.');
        } catch (\Anis\Partners\Errors\MalformedResponseException $exception) {
            self::assertStringNotContainsString('private-answer', $exception->getMessage());
        }

        $enrollmentWire = new SignedFakeWire();
        $enrollmentWire->body = '{"state":17}';
        $enrollment = \Anis\Partners\Enrollment\EnrollmentClient::create('https://partners.example', self::CARD, 'enrollment-token', $enrollmentWire, $factory, $factory);
        $this->expectException(\Anis\Partners\Errors\MalformedResponseException::class);
        $enrollment->getStatus();
    }

    #[Test]
    public function it_walks_catalogue_card_pages_for_a_wallet_and_subcategory(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26","subcategoryId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046"}],"nextCursor":"cards-2"}', 'headers' => ['X-Request-Id' => 'cards-1']],
            ['status' => 200, 'body' => '{"items":[{"id":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","subcategoryId":"4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046"}],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'cards-2']],
        ];
        self::assertSame(['2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34'], array_map(static fn(mixed $item): string => $item instanceof \Anis\Partners\Models\CatalogueCard ? $item->id : throw new \UnexpectedValueException(), iterator_to_array(self::client($wire)->catalogue()->listCards(self::WALLET, self::CARD))));

        self::assertStringContainsString('/catalog/subcategories/' . self::CARD . '/cards?cursor=cards-2', (string) $wire->requests[1]->getUri());
    }

    #[Test]
    public function it_walks_category_and_subcategory_pages(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26"}],"nextCursor":"category-2"}', 'headers' => ['X-Request-Id' => 'cat-1']],
            ['status' => 200, 'body' => '{"items":[{"id":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34"}],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'cat-2']],
            ['status' => 200, 'body' => '{"items":[{"id":"2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26","categoryId":"b672ca4e-c751-4507-8069-326496525a98"}],"nextCursor":"sub-2"}', 'headers' => ['X-Request-Id' => 'sub-1']],
            ['status' => 200, 'body' => '{"items":[{"id":"9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34","categoryId":"b672ca4e-c751-4507-8069-326496525a98"}],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'sub-2']],
        ];
        $catalogue = self::client($wire)->catalogue();
        self::assertSame(['2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34'], array_map(static fn(mixed $item): string => $item instanceof \Anis\Partners\Models\CatalogueCategory ? $item->id : throw new \UnexpectedValueException(), iterator_to_array($catalogue->listCategories(self::WALLET))));
        self::assertSame(['2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26', '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34'], array_map(static fn(mixed $item): string => $item instanceof \Anis\Partners\Models\CatalogueSubcategory ? $item->id : throw new \UnexpectedValueException(), iterator_to_array($catalogue->listSubcategories(self::WALLET, self::CARD))));

        self::assertStringContainsString('/catalog/categories?cursor=category-2', (string) $wire->requests[1]->getUri());
        self::assertStringContainsString('/catalog/categories/' . self::CARD . '/subcategories?cursor=sub-2', (string) $wire->requests[3]->getUri());
    }

    #[Test]
    public function it_creates_a_fresh_signature_for_each_caller_retry(): void
    {
        $wire = new SignedFakeWire();
        $wire->status = 201;
        $wire->body = '{"operationId":"' . self::OPERATION . '","status":"completed"}';
        $client = self::client($wire);
        $client->orders()->create(self::WALLET, self::OPERATION, self::order());
        $firstNonce = $wire->requests[0]->getHeaderLine('Nonce');
        $client->orders()->resume(self::WALLET, self::OPERATION, self::order());

        self::assertNotSame($firstNonce, $wire->requests[2]->getHeaderLine('Nonce'));
        self::assertCount(1, $wire->requests[2]->getHeader('Signature'));
    }

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();
        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    private static function order(): CreateOrderRequest
    {
        return new CreateOrderRequest(self::CARD, 2, Money::of('10.5', 'LYD'), Money::of('21', 'LYD'));
    }

    /** @return list<string> */
    private static function components(string $signatureInput): array
    {
        preg_match('/=\(([^)]*)\)/', $signatureInput, $matches);
        preg_match_all('/"([^"]+)"/', $matches[1] ?? '', $components);
        return $components[1];
    }
}
