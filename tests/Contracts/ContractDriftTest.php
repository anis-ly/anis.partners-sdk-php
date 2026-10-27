<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Contracts;

use Anis\Partners\Errors\ErrorCode;
use Anis\Partners\Models\DirectRechargeAccepted;
use Anis\Partners\Models\DirectRechargeProcessing;
use Anis\Partners\Models\EnrollmentKeyResult;
use Anis\Partners\Operations\DirectRechargeOperations;
use Anis\Partners\Operations\PartnerRoutes;
use Anis\Partners\Signing\SignatureProfile;
use Anis\Partners\Verification\SigningKeySet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Detects drift between the SDK's public map and the published partner surface. */
final class ContractDriftTest extends TestCase
{
    /**
     * The gateway publishes three test endpoints for the allowed-address list that exist on the staging environment only.
     * The SDK deliberately never covers them, so a comparison with the gateway contract excludes exactly these.
     */
    private const STAGING_TEST_ONLY_ROUTES = [
        'GET /v1/application/networks',
        'POST /v1/application/networks',
        'POST /v1/application/networks/remove',
    ];

    /** @return list<array{method: string, path: string, kind: string, routeId: ?string, scope: ?string, walletBound: bool, signed: bool, availability: ?string}> */
    private static function contractRoutes(bool $sdkOnly = true): array
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
                $metadata = self::stringKeyedObject($routeMetadata);
                $kind = $metadata['requestKind'] ?? null;
                if (!is_string($kind)) {
                    throw new \UnexpectedValueException('A public route has no request kind.');
                }
                $routeId = $metadata['routeId'] ?? null;
                $scope = $metadata['requiredScope'] ?? null;
                $walletBound = $metadata['walletBound'] ?? false;
                $availability = $operation['x-anis-availability'] ?? null;
                if ($availability !== null && !is_string($availability)) {
                    throw new \UnexpectedValueException('A public route has malformed availability metadata.');
                }
                if (!is_string($routeId) || ($scope !== null && !is_string($scope)) || !is_bool($walletBound) || !is_bool($metadata['signsResponse'] ?? null)) {
                    throw new \UnexpectedValueException('A public route has incomplete metadata.');
                }
                $routes[] = ['method' => strtoupper($method), 'path' => $path, 'kind' => $kind, 'routeId' => $routeId, 'scope' => $scope, 'walletBound' => $walletBound, 'signed' => $metadata['signsResponse'], 'availability' => $availability];
            }
        }

        return $sdkOnly ? array_values(array_filter($routes, static fn(array $route): bool => !in_array($route['method'] . ' ' . $route['path'], self::STAGING_TEST_ONLY_ROUTES, true))) : $routes;
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
            'POST /v1/application/suspend',
            'POST /v1/keys/{keyId}/revoke',
            'POST /v1/wallets/{walletId}/orders',
            'GET /v1/orders/{operationId}',
            'POST /v1/wallets/{walletId}/owned-cards/{soldCardId}/reveal',
            'POST /v1/wallets/{walletId}/invoices/{invoiceId}/owned-cards/reveal',
            'GET /v1/enrollments/{invitationId}',
            'POST /v1/enrollments/{invitationId}/keys',
            'POST /v1/enrollments/{invitationId}/proof',
            'GET /v1/enrollments/{invitationId}/status',
            'POST /v1/diagnostics/signature',
            'POST /v1/wallets/{walletId}/meter-charges',
            'GET /v1/meter-charges/{operationId}',
            'POST /v1/wallets/{walletId}/direct-recharges',
            'GET /v1/direct-recharges/{operationId}',
            'PATCH /v1/direct-recharges/{operationId}/recipient',
            'PUT /v1/webhook-endpoint',
            'POST /v1/webhook-endpoint/test',
            'POST /v1/webhook-events/{eventId}/redeliver',
        ];
        $unsigned = [
            'GET /v1/wallets/{walletId}/catalog/cards/{cardId}',
            'GET /v1/profile',
            'GET /v1/wallets',
            'GET /v1/wallets/{walletId}',
            'GET /v1/wallets/{walletId}/catalog/categories',
            'GET /v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories',
            'GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}',
            'GET /v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards',
            'GET /v1/wallets/{walletId}/owned-cards',
            'GET /v1/wallets/{walletId}/owned-cards/{soldCardId}',
            'GET /.well-known/partner-signing-keys.json',
            'GET /v1/wallets/{walletId}/meter-packages',
            'GET /v1/wallets/{walletId}/meter-charges',
            'GET /v1/wallets/{walletId}/direct-recharge-products',
            'GET /v1/wallets/{walletId}/direct-recharge-products/{productId}',
            'GET /v1/wallets/{walletId}/direct-recharge-products/{productId}/locations',
            'GET /v1/wallets/{walletId}/direct-recharge-products/{productId}/quote',
            'GET /v1/wallets/{walletId}/direct-recharges',
            'GET /v1/webhook-endpoint',
            'GET /v1/webhook-events',
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
        self::assertCount(39, PartnerRoutes::all());
        self::assertCount(19, $sdkSigned);
        self::assertCount(20, $sdkUnsigned);
    }

    #[Test]
    public function it_states_the_route_id_scope_wallet_binding_and_signed_answer_the_contract_publishes(): void
    {
        $published = [];
        foreach (self::contractRoutes() as $route) {
            $published[$route['method'] . ' ' . $route['path']] = $route;
        }
        foreach (PartnerRoutes::all() as $route) {
            $contract = $published[$route->method . ' ' . $route->template];
            self::assertSame($contract['routeId'], $route->routeId, $route->template);
            self::assertSame($contract['scope'], $route->requiredScope, $route->template);
            self::assertSame($contract['walletBound'], $route->walletBound, $route->template);
            self::assertSame($contract['signed'], $route->signsResponse, $route->template);
        }
    }

    #[Test]
    public function it_covers_every_partner_route_except_the_staging_only_test_endpoints(): void
    {
        $contract = array_map(static fn(array $route): string => $route['method'] . ' ' . $route['path'], self::contractRoutes(false));
        $sdk = array_map(static fn($route): string => $route->method . ' ' . $route->template, PartnerRoutes::all());

        foreach (self::STAGING_TEST_ONLY_ROUTES as $excluded) {
            self::assertContains($excluded, $contract);
            self::assertNotContains($excluded, $sdk);
        }
        $document = self::jsonObject(dirname(__DIR__, 2) . '/contracts/partner-public-v1.json');
        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        self::assertGreaterThanOrEqual(88, count($schemas), 'Keep the full gateway schema catalogue.');
        self::assertCount(42, $contract);
        self::assertCount(21, array_filter(self::contractRoutes(false), static fn(array $route): bool => $route['signed']));
        self::assertCount(21, array_filter(self::contractRoutes(false), static fn(array $route): bool => !$route['signed']));
        self::assertCount(39, self::contractRoutes());
        self::assertCount(39, $sdk);
        // Their refusal codes stay known so a response that carries one is typed.
        foreach (['partner_network_limit_reached', 'network_not_removable'] as $code) {
            self::assertNotSame(ErrorCode::Unknown, ErrorCode::parse($code));
        }
    }

    #[Test]
    public function it_grants_the_four_product_scopes_and_no_scope_to_webhook_self_service(): void
    {
        $scopes = [];
        foreach (PartnerRoutes::all() as $route) {
            if (str_contains($route->template, 'webhook')) {
                self::assertNull($route->requiredScope);
            }
            if ($route->requiredScope !== null && (str_starts_with($route->requiredScope, 'meter_charges:') || str_starts_with($route->requiredScope, 'direct_recharges:'))) {
                $scopes[$route->requiredScope] = true;
            }
            self::assertNotSame('statement:read', $route->requiredScope);
        }
        $names = array_keys($scopes);
        sort($names);
        self::assertSame(['direct_recharges:create', 'direct_recharges:read', 'meter_charges:create', 'meter_charges:read'], $names);
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

    #[Test]
    public function it_excludes_exactly_the_routes_marked_staging_only_by_the_gateway(): void
    {
        $marked = array_map(
            static fn(array $route): string => $route['method'] . ' ' . $route['path'],
            array_filter(self::contractRoutes(false), static fn(array $route): bool => $route['availability'] === 'staging-only'),
        );
        $excluded = self::STAGING_TEST_ONLY_ROUTES;
        sort($marked);
        sort($excluded);

        self::assertSame($excluded, $marked);
    }

    #[Test]
    public function it_preserves_both_published_key_purposes_and_accepts_legacy_keys_without_one(): void
    {
        $contract = self::jsonObject(dirname(__DIR__, 2) . '/contracts/partner-public-v1.json');
        $components = $contract['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $schema = $schemas['PublishedSigningKey'] ?? null;
        self::assertIsArray($schema);
        $required = $schema['required'] ?? null;
        self::assertIsArray($required);
        self::assertNotContains('purpose', $required);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        $purpose = $properties['purpose'] ?? null;
        self::assertIsArray($purpose);
        self::assertSame('string', $purpose['type'] ?? null);
        self::assertSame(['answer', 'webhook'], $purpose['enum'] ?? null);

        // Purpose is emitted by the service; older key documents may omit it. Never invent a purpose for such a key.
        $key = ['kty' => 'EC', 'crv' => 'P-256', 'kid' => 'published-key', 'x' => 'x', 'y' => 'y',
            'use' => 'sig', 'alg' => 'ES256', 'status' => 'active', 'notBefore' => '2026-10-20T00:00:00Z'];
        foreach (['answer', 'webhook'] as $value) {
            $keys = SigningKeySet::fromJson(json_encode(['keys' => [$key + ['purpose' => $value]]], JSON_THROW_ON_ERROR));
            self::assertCount(1, $keys->keys);
            self::assertSame($value, $keys->keys[0]->purpose);
            self::assertSame($value, $keys->keys[0]->toArray()['purpose']);
        }
        $legacy = SigningKeySet::fromJson(json_encode(['keys' => [$key]], JSON_THROW_ON_ERROR));
        self::assertCount(1, $legacy->keys);
        self::assertNull($legacy->keys[0]->purpose);
        self::assertArrayNotHasKey('purpose', $legacy->keys[0]->toArray());
    }

    #[Test]
    public function it_matches_the_published_signed_recipient_acknowledgement_and_location(): void
    {
        $contract = self::jsonObject(dirname(__DIR__, 2) . '/contracts/partner-public-v1.json');
        $paths = $contract['paths'] ?? null;
        self::assertIsArray($paths);
        $path = $paths['/v1/direct-recharges/{operationId}/recipient'] ?? null;
        self::assertIsArray($path);
        $patch = $path['patch'] ?? null;
        self::assertIsArray($patch);
        $metadata = $patch['x-anis-route'] ?? null;
        self::assertIsArray($metadata);
        self::assertTrue($metadata['signsResponse'] ?? null);
        self::assertSame('bodylessNonceMutation', $metadata['requestKind'] ?? null);
        $responses = $patch['responses'] ?? null;
        self::assertIsArray($responses);
        $successStatuses = array_values(array_filter(array_keys($responses), static fn(int|string $status): bool => preg_match('/\A2[0-9]{2}\z/', (string) $status) === 1));
        self::assertSame([202], $successStatuses, 'Recipient PATCH acknowledges acceptance without returning an exchange read.');
        $accepted = $responses['202'] ?? null;
        self::assertIsArray($accepted);
        $content = $accepted['content'] ?? null;
        self::assertIsArray($content);
        $json = $content['application/json'] ?? null;
        self::assertIsArray($json);
        self::assertSame(['$ref' => '#/components/schemas/DirectRechargeAccepted'], $json['schema'] ?? null);
        $headers = $accepted['headers'] ?? null;
        self::assertIsArray($headers);
        foreach (['Location', 'Signature', 'Signature-Input'] as $header) {
            self::assertArrayHasKey($header, $headers);
        }
        self::assertSame(['$ref' => '#/components/headers/Location'], $headers['Location']);
        $return = (new \ReflectionMethod(DirectRechargeOperations::class, 'updateRecipient'))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $return);
        self::assertSame(DirectRechargeProcessing::class, $return->getName());
        $components = $contract['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $schema = $schemas['DirectRechargeAccepted'] ?? null;
        self::assertIsArray($schema);
        self::assertFalse($schema['additionalProperties'] ?? null);
        $required = $schema['required'] ?? null;
        self::assertIsArray($required);
        sort($required);
        $wire = ['operationId' => '7d4e1a90-5b2c-4e7f-8a13-0c9d6e2b4f81', 'operationType' => 'directRecharge', 'status' => 'processing'];
        $model = DirectRechargeAccepted::fromArray($wire);
        self::assertArrayNotHasKey('location', get_object_vars($model));
        self::assertSame($wire, $model->jsonSerialize());
        $members = array_keys($model->jsonSerialize());
        sort($members);
        self::assertSame($required, $members);
        self::assertArrayNotHasKey('location', $model->jsonSerialize());
    }
}
