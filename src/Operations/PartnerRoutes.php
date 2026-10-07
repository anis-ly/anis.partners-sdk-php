<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Signing\SignatureProfile;

/**
 * Holds the closed route set so contract drift can be checked in both directions.
 *
 * Each route states whether Anis signs its answers. Only the answers that move money, deliver card codes, or establish
 * a key are signed — orders, reveals, enrolment, and the signature self-check — and on those routes every answer,
 * success and refusal, is verified. The information reads (profile, wallets, catalogue, owned-card lists) and the
 * signing-key document are not signed by Anis, so their answers are passed through unverified. Every request is
 * still signed exactly as before.
 */
final class PartnerRoutes
{
    /** @return list<PartnerRoute> */
    public static function all(): array
    {
        return [
            new PartnerRoute('GET', '/v1/profile', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/categories', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards', SignatureProfile::SafeRead, false),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/orders', SignatureProfile::OrderMutation, true),
            new PartnerRoute('GET', '/v1/orders/{operationId}', SignatureProfile::SafeRead, true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/cards', SignatureProfile::SafeRead, false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/cards/{soldCardId}', SignatureProfile::SafeRead, false),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/cards/{soldCardId}/reveal', SignatureProfile::BodylessNonceMutation, true),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal', SignatureProfile::BodylessNonceMutation, true),
            new PartnerRoute('POST', '/v1/diagnostics/signature', SignatureProfile::BodylessNonceMutation, true),
            new PartnerRoute('GET', '/v1/enrollments/{invitationId}', null, true),
            new PartnerRoute('POST', '/v1/enrollments/{invitationId}/keys', null, true),
            new PartnerRoute('POST', '/v1/enrollments/{invitationId}/proof', null, true),
            new PartnerRoute('GET', '/v1/enrollments/{invitationId}/status', null, true),
            new PartnerRoute('GET', '/.well-known/partner-signing-keys.json', null, false),
        ];
    }

    /**
     * Says whether Anis signs the answers of a route, so its answers must be verified.
     *
     * The decision is explicit per route: it never depends on whether an answer happens to carry a signature. A route
     * outside the closed set is treated as signed, so an unknown route can never take the unverified path.
     */
    public static function signsResponse(string $method, string $template): bool
    {
        foreach (self::all() as $route) {
            if ($route->method === strtoupper($method) && $route->template === $template) {
                return $route->signsResponse;
            }
        }

        return true;
    }
}
