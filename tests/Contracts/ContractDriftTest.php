<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Contracts;

use Anis\Partners\Errors\ErrorCode;
use Anis\Partners\Models\EnrollmentKeyResult;
use Anis\Partners\Operations\PartnerRoutes;
use Anis\Partners\Signing\SignatureProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Detects drift between the SDK's public map and the published partner surface. */
final class ContractDriftTest extends TestCase
{
    /** @return list<array{method: string, path: string, kind: string}> */
    private static function contractRoutes(): array
    {
        $contract = self::jsonObject(dirname(__DIR__, 2) . '/contracts/partner-public-v1.json');
        $paths = $contract['paths'] ?? null;
        if (!is_array($paths)) {
            throw new \UnexpectedValueException('The public contract must contain route paths.');
        }

        $routes = [];
        foreach ($paths as $path => $methodMap) {
            if (!is_string($path) || !is_array($methodMap)) {
                throw new \UnexpectedValueException('The public contract route map is malformed.');
            }
            foreach ($methodMap as $method => $rawOperation) {
                if (!is_string($method) || !is_array($rawOperation)) {
                    throw new \UnexpectedValueException('A public contract operation is malformed.');
                }
                $operation = self::stringKeyedObject($rawOperation);
                $routeMetadata = $operation['x-anis-route'] ?? null;
                if (!is_array($routeMetadata)) {
                    throw new \UnexpectedValueException('A public route has no signing metadata.');
                }
                $kind = self::stringKeyedObject($routeMetadata)['requestKind'] ?? null;
                if (!is_string($kind)) {
                    throw new \UnexpectedValueException('A public route has no request kind.');
                }
                $routes[] = ['method' => strtoupper($method), 'path' => $path, 'kind' => $kind];
            }
        }

        return $routes;
    }

    /** @return list<string> */
    private static function publicCodes(): array
    {
        $catalogue = self::jsonObject(dirname(__DIR__, 2) . '/contracts/error-catalogue.json');
        $representations = $catalogue['representations'] ?? null;
        if (!is_array($representations)) {
            throw new \UnexpectedValueException('The error catalogue must contain representations.');
        }

        $codes = [];
        foreach ($representations as $representation) {
            if (!is_array($representation)) {
                throw new \UnexpectedValueException('An error catalogue representation is malformed.');
            }
            $item = self::stringKeyedObject($representation);
            if (($item['publicDocumentation'] ?? false) === true && is_string($item['publicCode'] ?? null)) {
                $codes[] = $item['publicCode'];
            }
        }

        return $codes;
    }

    /** @return array<string, mixed> */
    private static function jsonObject(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('A checked-in contract file could not be read.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('A checked-in contract must be a JSON object.');
        }

        return self::stringKeyedObject($decoded);
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeyedObject(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A checked-in contract object has a non-string key.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    #[Test]
    public function it_matches_the_published_route_table_in_both_directions(): void
    {
        $published = array_map(static fn(array $route): array => [$route['method'], $route['path']], self::contractRoutes());
        $sdk = array_map(static fn($route): array => [$route->method, $route->template], PartnerRoutes::all());
        sort($published);
        sort($sdk);
        self::assertSame($published, $sdk);
    }

    #[Test]
    public function it_uses_the_request_kind_assigned_to_each_route(): void
    {
        $published = [];
        foreach (self::contractRoutes() as $route) {
            $published[$route['method'] . ' ' . $route['path']] = $route['kind'];
        }
        foreach (PartnerRoutes::all() as $route) {
            $key = $route->method . ' ' . $route->template;
            $kind = match ($route->profile) {
                SignatureProfile::SafeRead => 'safeRead',
                SignatureProfile::BodylessNonceMutation => 'bodylessNonceMutation',
                SignatureProfile::OrderMutation => 'orderMutation',
                null => in_array($route->template, [
                    '/v1/enrollments/{invitationId}',
                    '/v1/enrollments/{invitationId}/keys',
                    '/v1/enrollments/{invitationId}/proof',
                    '/v1/enrollments/{invitationId}/status',
                ], true) ? 'enrollmentToken' : 'public',
            };
            self::assertSame($kind, $published[$key]);
        }
    }

    #[Test]
    public function it_verifies_the_answers_of_exactly_the_routes_anis_signs(): void
    {
        // The gateway's catalogue (PartnerRoute.SignsResponse) is the authority; its answers on these routes are signed.
        $signed = [
            'POST /v1/wallets/{walletId}/orders',
            'GET /v1/orders/{operationId}',
            'POST /v1/wallets/{walletId}/cards/{soldCardId}/reveal',
            'POST /v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal',
            'GET /v1/enrollments/{invitationId}',
            'POST /v1/enrollments/{invitationId}/keys',
            'POST /v1/enrollments/{invitationId}/proof',
            'GET /v1/enrollments/{invitationId}/status',
            'POST /v1/diagnostics/signature',
        ];
        $unsigned = [
            'GET /v1/profile',
            'GET /v1/wallets',
            'GET /v1/wallets/{walletId}',
            'GET /v1/wallets/{walletId}/catalog/categories',
            'GET /v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories',
            'GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}',
            'GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards',
            'GET /v1/wallets/{walletId}/cards',
            'GET /v1/wallets/{walletId}/cards/{soldCardId}',
            'GET /.well-known/partner-signing-keys.json',
        ];
        $sdkSigned = [];
        $sdkUnsigned = [];
        foreach (PartnerRoutes::all() as $route) {
            $key = $route->method . ' ' . $route->template;
            self::assertSame($route->signsResponse, PartnerRoutes::signsResponse($route->method, $route->template));
            if ($route->signsResponse) {
                $sdkSigned[] = $key;
            } else {
                $sdkUnsigned[] = $key;
            }
        }
        sort($signed);
        sort($unsigned);
        sort($sdkSigned);
        sort($sdkUnsigned);
        self::assertSame($signed, $sdkSigned);
        self::assertSame($unsigned, $sdkUnsigned);
    }

    #[Test]
    public function it_verifies_the_answer_of_a_route_outside_the_catalogue(): void
    {
        self::assertTrue(PartnerRoutes::signsResponse('GET', '/v1/unknown'));
        self::assertTrue(PartnerRoutes::signsResponse('POST', '/v1/profile'));
    }

    #[Test]
    public function it_knows_every_public_error_code_in_the_catalogue(): void
    {
        $codes = array_values(array_unique(self::publicCodes()));
        $known = array_map(static fn(ErrorCode $code): string => $code->value, array_filter(
            ErrorCode::cases(),
            static fn(ErrorCode $code): bool => $code !== ErrorCode::Unknown,
        ));
        sort($codes);
        sort($known);
        self::assertSame($codes, $known);
    }

    #[Test]
    public function it_carries_exactly_the_published_key_submission_members(): void
    {
        $contract = self::jsonObject(dirname(__DIR__, 2) . '/contracts/partner-public-v1.json');
        $components = $contract['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        $schema = is_array($schemas) ? ($schemas['EnrollmentKeyResult'] ?? null) : null;
        $properties = is_array($schema) ? ($schema['properties'] ?? null) : null;
        if (!is_array($properties)) {
            throw new \UnexpectedValueException('The public contract has no EnrollmentKeyResult schema.');
        }
        $published = array_keys($properties);
        $model = EnrollmentKeyResult::fromArray([
            'keyId' => '7a1c3e5f-2b4d-4f68-8a0c-9e1b3d5f7a2c',
            'thumbprint' => 'abc',
            'safetyCode' => 'ABCD-EFGH-JKLM-NPQR',
            'challenge' => 'challenge',
            'challengeGeneration' => 2,
        ]);
        $members = array_keys(get_object_vars($model));
        sort($published);
        sort($members);
        self::assertSame($published, $members);
    }

    #[Test]
    public function it_generates_the_error_enum_from_the_current_catalogue(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/generate-errors.php';
        self::assertSame(
            generateErrorCodeSource(dirname(__DIR__, 2) . '/contracts/error-catalogue.json'),
            file_get_contents(dirname(__DIR__, 2) . '/src/Errors/ErrorCode.php'),
        );
    }

    #[Test]
    public function it_keeps_the_covered_signature_components_in_contract_order(): void
    {
        self::assertSame(['@method', '@authority', '@path', '@query', 'x-anis-date'], SignatureProfile::SafeRead->components());
        self::assertSame(['@method', '@authority', '@path', '@query', 'content-digest', 'nonce', 'x-anis-date'], SignatureProfile::BodylessNonceMutation->components());
        self::assertSame(['@method', '@authority', '@path', '@query', 'content-digest', 'nonce', 'idempotency-key', 'x-anis-date'], SignatureProfile::OrderMutation->components());
    }
}
