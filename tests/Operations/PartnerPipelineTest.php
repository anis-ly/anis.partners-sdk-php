<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\OrderCompleted;
use Anis\Partners\Models\RevealedCredential;
use Anis\Partners\Models\RevealedCredentialCollection;
use Anis\Partners\Models\SignatureDiagnostic;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\SignatureProfile;
use Anis\Partners\Tests\Support\SignedFakeWire;
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
            self::client($wire)->profile()->get();
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
    public function it_refuses_to_return_a_body_changed_after_signing(): void
    {
        $wire = new SignedFakeWire();
        $wire->tamperAfterSigning = true;
        $client = self::client($wire);

        $this->expectException(UnverifiableResponseException::class);
        $client->profile()->get();
    }

    #[Test]
    public function it_follows_every_page_and_signs_the_encoded_cursor_it_sends(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"page / 2"}', 'headers' => ['X-Request-Id' => 'req-1']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'req-2']],
        ];
        $client = self::client($wire);
        self::assertSame([], iterator_to_array($client->wallets()->list()));

        self::assertCount(3, $wire->requests);
        self::assertStringContainsString('cursor=page%20%2F%202', (string) $wire->requests[2]->getUri());
        self::assertNotSame('', $wire->requests[2]->getHeaderLine('Signature-Input'));
    }

    #[Test]
    public function it_walks_owned_card_pages_until_the_cursor_is_empty(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"owned-2"}', 'headers' => ['X-Request-Id' => 'owned-1']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'owned-2']],
        ];
        self::assertSame([], iterator_to_array(self::client($wire)->ownedCards()->list(self::WALLET)));

        self::assertStringContainsString('/v1/wallets/' . self::WALLET . '/cards?cursor=owned-2', (string) $wire->requests[2]->getUri());
    }

    #[Test]
    public function it_walks_catalogue_card_pages_for_a_wallet_and_subcategory(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"cards-2"}', 'headers' => ['X-Request-Id' => 'cards-1']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'cards-2']],
        ];
        self::assertSame([], iterator_to_array(self::client($wire)->catalogue()->listCards(self::WALLET, self::CARD)));

        self::assertStringContainsString('/catalog/subcategories/' . self::CARD . '/cards?cursor=cards-2', (string) $wire->requests[2]->getUri());
    }

    #[Test]
    public function it_walks_category_and_subcategory_pages(): void
    {
        $wire = new SignedFakeWire();
        $wire->responseQueue = [
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"category-2"}', 'headers' => ['X-Request-Id' => 'cat-1']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'cat-2']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":"sub-2"}', 'headers' => ['X-Request-Id' => 'sub-1']],
            ['status' => 200, 'body' => '{"items":[],"nextCursor":null}', 'headers' => ['X-Request-Id' => 'sub-2']],
        ];
        $catalogue = self::client($wire)->catalogue();
        self::assertSame([], iterator_to_array($catalogue->listCategories(self::WALLET)));
        self::assertSame([], iterator_to_array($catalogue->listSubcategories(self::WALLET, self::CARD)));

        self::assertStringContainsString('/catalog/categories?cursor=category-2', (string) $wire->requests[2]->getUri());
        self::assertStringContainsString('/catalog/categories/' . self::CARD . '/subcategories?cursor=sub-2', (string) $wire->requests[4]->getUri());
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
