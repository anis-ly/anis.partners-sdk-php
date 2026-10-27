<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Signing\SignatureProfile;

/**
 * Holds the closed route set so contract drift can be checked in both directions.
 *
 * Each route states whether Anis signs its answers. Only the answers that move money, deliver card codes, stop access, or establish
 * a key are signed — orders, reveals, access stops, enrolment, and the signature self-check — and on those routes every answer,
 * success and refusal, is verified. The information reads (profile, wallets, catalogue, owned-card lists) and the
 * signing-key document are not signed by Anis, so their answers are passed through unverified. Partner requests carry a signature; enrollment uses its token and proof.
 */
final class PartnerRoutes
{
    /** @return list<PartnerRoute> */
    public static function all(): array
    {
        return [
            new PartnerRoute('POST', '/v1/application/suspend', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.access.application.suspend', requiredScope: null, walletBound: false),
            new PartnerRoute('POST', '/v1/keys/{keyId}/revoke', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.access.key.revoke', requiredScope: null, walletBound: false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/cards/{cardId}', SignatureProfile::SafeRead, false, routeId: 'partner.catalogue.card.read', requiredScope: 'catalogue:read', walletBound: true),
            new PartnerRoute('GET', '/v1/profile', SignatureProfile::SafeRead, false, routeId: 'partner.profile.read', requiredScope: 'profile:read', walletBound: false),
            new PartnerRoute('GET', '/v1/wallets', SignatureProfile::SafeRead, false, routeId: 'partner.wallets.list', requiredScope: 'wallets:read', walletBound: false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}', SignatureProfile::SafeRead, false, routeId: 'partner.wallets.read', requiredScope: 'wallets:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/categories', SignatureProfile::SafeRead, false, routeId: 'partner.catalogue.categories.list', requiredScope: 'catalogue:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories', SignatureProfile::SafeRead, false, routeId: 'partner.catalogue.category-subcategories.list', requiredScope: 'catalogue:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}', SignatureProfile::SafeRead, false, routeId: 'partner.catalogue.subcategory.read', requiredScope: 'catalogue:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards', SignatureProfile::SafeRead, false, routeId: 'partner.catalogue.subcategory-cards.list', requiredScope: 'catalogue:read', walletBound: true),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/orders', SignatureProfile::OrderMutation, true, routeId: 'partner.orders.create', requiredScope: 'orders:create', walletBound: true),
            new PartnerRoute('GET', '/v1/orders/{operationId}', SignatureProfile::SafeRead, true, routeId: 'partner.orders.read', requiredScope: 'orders:read', walletBound: false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/owned-cards', SignatureProfile::SafeRead, false, routeId: 'partner.cards.list', requiredScope: 'cards:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/owned-cards/{soldCardId}', SignatureProfile::SafeRead, false, routeId: 'partner.cards.read', requiredScope: 'cards:read', walletBound: true),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/owned-cards/{soldCardId}/reveal', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.cards.reveal', requiredScope: 'cards:reveal', walletBound: true),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/invoices/{invoiceId}/owned-cards/reveal', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.invoices.cards.reveal', requiredScope: 'cards:reveal', walletBound: true),
            new PartnerRoute('POST', '/v1/diagnostics/signature', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.diagnostics.signature', requiredScope: 'diagnostics:use', walletBound: false),
            new PartnerRoute('GET', '/v1/enrollments/{invitationId}', null, true, routeId: 'partner.enrollments.read', requiredScope: null, walletBound: false),
            new PartnerRoute('POST', '/v1/enrollments/{invitationId}/keys', null, true, routeId: 'partner.enrollments.keys.submit', requiredScope: null, walletBound: false),
            new PartnerRoute('POST', '/v1/enrollments/{invitationId}/proof', null, true, routeId: 'partner.enrollments.proof.submit', requiredScope: null, walletBound: false),
            new PartnerRoute('GET', '/v1/enrollments/{invitationId}/status', null, true, routeId: 'partner.enrollments.status.read', requiredScope: null, walletBound: false),
            new PartnerRoute('GET', '/.well-known/partner-signing-keys.json', null, false, routeId: 'partner.signing-keys.read', requiredScope: null, walletBound: false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/meter-packages', SignatureProfile::SafeRead, false, routeId: 'partner.meter-packages.getMeterPackages', requiredScope: 'meter_charges:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/meter-charges', SignatureProfile::SafeRead, false, routeId: 'partner.meter-charges.getMeterCharges', requiredScope: 'meter_charges:read', walletBound: true),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/meter-charges', SignatureProfile::OrderMutation, true, routeId: 'partner.meter-charges.create', requiredScope: 'meter_charges:create', walletBound: true),
            new PartnerRoute('GET', '/v1/meter-charges/{operationId}', SignatureProfile::SafeRead, true, routeId: 'partner.meter-charges.read', requiredScope: 'meter_charges:read', walletBound: false),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/direct-recharge-products', SignatureProfile::SafeRead, false, routeId: 'partner.direct-recharge-products.getDirectRechargeProducts', requiredScope: 'direct_recharges:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/direct-recharge-products/{productId}', SignatureProfile::SafeRead, false, routeId: 'partner.direct-recharge-products.read', requiredScope: 'direct_recharges:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/direct-recharge-products/{productId}/locations', SignatureProfile::SafeRead, false, routeId: 'partner.direct-recharge-products.locations.getDirectRechargeProductLocations', requiredScope: 'direct_recharges:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/direct-recharge-products/{productId}/quote', SignatureProfile::SafeRead, false, routeId: 'partner.direct-recharge-products.quote', requiredScope: 'direct_recharges:read', walletBound: true),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/direct-recharges', SignatureProfile::SafeRead, false, routeId: 'partner.direct-recharges.getDirectRecharges', requiredScope: 'direct_recharges:read', walletBound: true),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/direct-recharges', SignatureProfile::OrderMutation, true, routeId: 'partner.direct-recharges.create', requiredScope: 'direct_recharges:create', walletBound: true),
            new PartnerRoute('GET', '/v1/direct-recharges/{operationId}', SignatureProfile::SafeRead, true, routeId: 'partner.direct-recharges.read', requiredScope: 'direct_recharges:read', walletBound: false),
            new PartnerRoute('PATCH', '/v1/direct-recharges/{operationId}/recipient', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.direct-recharges.recipient.update', requiredScope: 'direct_recharges:create', walletBound: false),
            new PartnerRoute('GET', '/v1/webhook-endpoint', SignatureProfile::SafeRead, false, routeId: 'partner.webhook-endpoint.read', requiredScope: null, walletBound: false),
            new PartnerRoute('PUT', '/v1/webhook-endpoint', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.webhook-endpoint.update', requiredScope: null, walletBound: false),
            new PartnerRoute('POST', '/v1/webhook-endpoint/test', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.webhook-endpoint.test', requiredScope: null, walletBound: false),
            new PartnerRoute('GET', '/v1/webhook-events', SignatureProfile::SafeRead, false, routeId: 'partner.webhook-events.getWebhookEvents', requiredScope: null, walletBound: false),
            new PartnerRoute('POST', '/v1/webhook-events/{eventId}/redeliver', SignatureProfile::BodylessNonceMutation, true, routeId: 'partner.webhook-events.redeliver', requiredScope: null, walletBound: false),
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
