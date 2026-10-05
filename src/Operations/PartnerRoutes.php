<?php

declare(strict_types=1);

namespace Anis\Partners\Operations;

use Anis\Partners\Signing\SignatureProfile;

/** Holds the closed route set so contract drift can be checked in both directions. */
final class PartnerRoutes
{
    /** @return list<PartnerRoute> */
    public static function all(): array
    {
        return [
            new PartnerRoute('GET', '/v1/profile', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/categories', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/categories/{categoryId}/subcategories', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/catalog/subcategories/{subcategoryId}/cards', SignatureProfile::SafeRead),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/orders', SignatureProfile::OrderMutation),
            new PartnerRoute('GET', '/v1/orders/{operationId}', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/cards', SignatureProfile::SafeRead),
            new PartnerRoute('GET', '/v1/wallets/{walletId}/cards/{soldCardId}', SignatureProfile::SafeRead),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/cards/{soldCardId}/reveal', SignatureProfile::BodylessNonceMutation),
            new PartnerRoute('POST', '/v1/wallets/{walletId}/invoices/{invoiceId}/cards/reveal', SignatureProfile::BodylessNonceMutation),
            new PartnerRoute('POST', '/v1/diagnostics/signature', SignatureProfile::BodylessNonceMutation),
            new PartnerRoute('GET', '/v1/enrollments/{invitationId}', null),
            new PartnerRoute('POST', '/v1/enrollments/{invitationId}/keys', null),
            new PartnerRoute('POST', '/v1/enrollments/{invitationId}/proof', null),
            new PartnerRoute('GET', '/v1/enrollments/{invitationId}/status', null),
            new PartnerRoute('GET', '/.well-known/partner-signing-keys.json', null),
        ];
    }
}
