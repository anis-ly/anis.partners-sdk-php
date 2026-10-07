<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Operations;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Errors\AnisApiException;
use Anis\Partners\Errors\AuthorizationException;
use Anis\Partners\Errors\DependencyUnavailableException;
use Anis\Partners\Errors\ErrorCode;
use Anis\Partners\Errors\ResourceNotFoundException;
use Anis\Partners\Internal\Base64Url;
use Anis\Partners\Models\CreateOrderRequest;
use Anis\Partners\Models\EnrollmentKeyRequest;
use Anis\Partners\Models\EnrollmentProofRequest;
use Anis\Partners\Models\Money;
use Anis\Partners\Models\OrderOutcomeUnknown;
use Anis\Partners\Tests\Support\SignedFakeWire;
use Anis\Partners\Verification\ResponseVerificationFailure;
use Anis\Partners\Verification\UnverifiableResponseException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Proves that only the routes whose answers Anis signs are verified, and that those still refuse an unsigned answer. */
final class SelectiveResponseSigningTest extends TestCase
{
    private const WALLET = '2f1c8a94-6d37-4e52-b8a1-0c9e5d3f7b26';
    private const OPERATION = '9b2e4f17-3c6a-4d58-b0e1-7a5c8d2f6b34';
    private const CARD = '4a6c2e81-7b39-4d15-a2f8-3e7b9c1d5046';
    private const KEY = '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c';
    private const PAGE = '{"items":[],"nextCursor":null}';

    /** @return iterable<string, array{\Closure(SignedFakeWire): mixed, string}> */
    public static function informationReads(): iterable
    {
        yield 'GET /v1/profile' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->profile()->get(), '{}'];
        yield 'GET /v1/wallets' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->wallets()->listPage(), self::PAGE];
        yield 'GET /v1/wallets/{walletId}' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->wallets()->get(self::WALLET), '{"id":"' . self::WALLET . '"}'];
        yield 'GET /v1/wallets/{walletId}/catalog/categories' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->catalogue()->listCategoriesPage(self::WALLET), self::PAGE];
        yield 'GET /v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->catalogue()->listSubcategoriesPage(self::WALLET, self::CARD), self::PAGE];
        yield 'GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->catalogue()->getSubcategory(self::WALLET, self::CARD), '{"id":"' . self::CARD . '","categoryId":"' . self::WALLET . '"}'];
        yield 'GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->catalogue()->listCardsPage(self::WALLET, self::CARD), self::PAGE];
        yield 'GET /v1/wallets/{walletId}/cards' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->ownedCards()->listPage(self::WALLET), self::PAGE];
        yield 'GET /v1/wallets/{walletId}/cards/{soldCardId}' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->ownedCards()->get(self::WALLET, self::CARD), '{"id":"' . self::CARD . '"}'];
    }

    /** @return iterable<string, array{\Closure(SignedFakeWire): mixed, string}> */
    public static function signedRoutes(): iterable
    {
        yield 'POST /v1/wallets/{walletId}/orders' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->orders()->create(self::WALLET, self::OPERATION, self::order()), '{"operationId":"' . self::OPERATION . '","status":"completed"}'];
        yield 'GET /v1/orders/{operationId}' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->orders()->get(self::OPERATION), '{"operationId":"' . self::OPERATION . '","status":"completed"}'];
        yield 'POST /v1/wallets/{walletId}/cards/{soldCardId}/reveal' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->ownedCards()->reveal(self::WALLET, self::CARD), '{"soldCardId":"' . self::CARD . '","voucher":"unsigned-voucher"}'];
        yield 'POST /v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->ownedCards()->revealInvoice(self::WALLET, self::OPERATION), '{"invoiceId":"' . self::OPERATION . '","items":[]}'];
        yield 'POST /v1/diagnostics/signature' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->diagnostics()->checkSignature(), '{}'];
        yield 'GET /v1/enrollments/{invitationId}' => [static fn(SignedFakeWire $wire): mixed => self::enrollment($wire)->get(), '{"state":"pendingApproval"}'];
        yield 'POST /v1/enrollments/{invitationId}/keys' => [static fn(SignedFakeWire $wire): mixed => self::enrollment($wire)->submitKey(new EnrollmentKeyRequest($wire->requestPublicJwk(), new \DateTimeImmutable('now'), new \DateTimeImmutable('+1 year'))), '{"keyId":"' . self::KEY . '"}'];
        yield 'POST /v1/enrollments/{invitationId}/proof' => [static fn(SignedFakeWire $wire): mixed => self::enrollment($wire)->submitProof(new EnrollmentProofRequest(self::KEY, 1, Base64Url::encode(str_repeat("\x01", 64)))), '{"state":"pendingApproval"}'];
        yield 'GET /v1/enrollments/{invitationId}/status' => [static fn(SignedFakeWire $wire): mixed => self::enrollment($wire)->getStatus(), '{"state":"pendingApproval"}'];
    }

    /** @param \Closure(SignedFakeWire): mixed $call */
    #[Test]
    #[DataProvider('informationReads')]
    public function it_returns_an_information_answer_that_anis_does_not_sign(\Closure $call, string $body): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->body = $body;

        self::assertIsObject($call($wire));
        self::assertCount(1, $wire->requests, 'An information read must not fetch the signing-key document.');
        self::assertNotSame('', $wire->requests[0]->getHeaderLine('Signature'), 'Every request is still signed.');
        self::assertNotSame('', $wire->requests[0]->getHeaderLine('Signature-Input'));
    }

    /** @param \Closure(SignedFakeWire): mixed $call */
    #[Test]
    #[DataProvider('informationReads')]
    public function it_does_not_verify_an_information_answer_even_when_it_carries_a_signature(\Closure $call, string $body): void
    {
        $wire = new SignedFakeWire();
        $wire->body = $body;
        $wire->tamperAfterSigning = true;

        self::assertIsObject($call($wire));
        self::assertCount(1, $wire->requests, 'An information answer is never verified, so no signing key is fetched.');
    }

    /** @return iterable<string, array{\Closure(SignedFakeWire): mixed, int, string, class-string<AnisApiException>, ErrorCode}> */
    public static function informationRefusals(): iterable
    {
        yield 'wallet not granted' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->wallets()->get(self::WALLET), 404, 'wallet_not_granted', ResourceNotFoundException::class, ErrorCode::WalletNotGranted];
        yield 'insufficient scope' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->catalogue()->listCategoriesPage(self::WALLET), 403, 'insufficient_scope', AuthorizationException::class, ErrorCode::InsufficientScope];
        yield 'dependency unavailable' => [static fn(SignedFakeWire $wire): mixed => self::client($wire)->ownedCards()->listPage(self::WALLET), 503, 'dependency_unavailable', DependencyUnavailableException::class, ErrorCode::DependencyUnavailable];
    }

    /**
     * @param \Closure(SignedFakeWire): mixed $call
     * @param class-string<AnisApiException> $exception
     */
    #[Test]
    #[DataProvider('informationRefusals')]
    public function it_maps_an_unsigned_information_refusal_to_its_typed_error(\Closure $call, int $status, string $code, string $exception, ErrorCode $errorCode): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->status = $status;
        $wire->body = '{"status":' . $status . ',"code":"' . $code . '","requestId":"req-unsigned"}';
        $wire->responseHeaders = ['X-Request-Id' => 'req-unsigned'];

        try {
            $call($wire);
            self::fail('An unsigned information refusal must surface as a typed refusal.');
        } catch (AnisApiException $refusal) {
            self::assertInstanceOf($exception, $refusal);
            self::assertSame($errorCode, $refusal->errorCode);
            self::assertSame($status, $refusal->status);
            self::assertSame('req-unsigned', $refusal->requestId);
            self::assertCount(1, $wire->requests);
        }
    }

    /** @param \Closure(SignedFakeWire): mixed $call */
    #[Test]
    #[DataProvider('signedRoutes')]
    public function it_refuses_a_signed_route_answer_that_carries_no_signature(\Closure $call, string $body): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->body = $body;

        self::assertSame(ResponseVerificationFailure::SignatureMissing, self::verificationFailure($call, $wire), 'A signed route must refuse an answer that carries no signature.');
    }

    /** @param \Closure(SignedFakeWire): mixed $call */
    #[Test]
    #[DataProvider('signedRoutes')]
    public function it_refuses_an_unsigned_refusal_on_a_signed_route(\Closure $call): void
    {
        $wire = new SignedFakeWire();
        $wire->signResponses = false;
        $wire->status = 404;
        $wire->body = '{"status":404,"code":"resource_not_found"}';

        self::assertSame(ResponseVerificationFailure::SignatureMissing, self::verificationFailure($call, $wire), 'A signed route must refuse a refusal that carries no signature.');
    }

    /**
     * Returns the rule that discarded the answer; an order create reports it as the cause of an unknown outcome.
     * @param \Closure(SignedFakeWire): mixed $call
     */
    private static function verificationFailure(\Closure $call, SignedFakeWire $wire): ?ResponseVerificationFailure
    {
        try {
            $result = $call($wire);
        } catch (UnverifiableResponseException $exception) {
            return $exception->failure();
        }
        if ($result instanceof OrderOutcomeUnknown && $result->cause instanceof UnverifiableResponseException) {
            return $result->cause->failure();
        }

        return null;
    }

    private static function client(SignedFakeWire $wire): AnisPartnersClient
    {
        $factory = new HttpFactory();

        return AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
    }

    private static function enrollment(SignedFakeWire $wire): EnrollmentClient
    {
        $factory = new HttpFactory();

        return EnrollmentClient::create('https://partners.example', self::WALLET, 'enrollment-token', $wire, $factory, $factory);
    }

    private static function order(): CreateOrderRequest
    {
        return new CreateOrderRequest(self::CARD, 2, Money::of('10.5', 'LYD'), Money::of('21', 'LYD'));
    }
}
