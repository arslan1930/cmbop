<?php

namespace App\Support;

/**
 * Invoice-only issuer branding. Do not print this on transactional mail.
 *
 * Companies House entity is Teqno Ltd (17392540). Owner-chosen invoice
 * branding is "Teqno Ltd — partner of SEOLinkBuildings". That is branding,
 * not a formal legal-partnership claim. Leftover "Partners with (Teqno LTD)"
 * is not a legal name.
 *
 * VAT non-registration is whatever billing config currently says — this class
 * does not invent or change tax status.
 */
class LegalIdentity
{
    public const LEGAL_ENTITY = 'Teqno Ltd';

    public const BRAND_NAME = 'SEOLinkBuildings';

    public const COMPANY_NUMBER = '17392540';

    public const ISSUER_NAME = 'Teqno Ltd — partner of SEOLinkBuildings';

    public const FOOTER = 'Teqno Ltd — partner of SEOLinkBuildings. Registered in England and Wales. Company number: 17392540.';

    public static function issuerName(): string
    {
        $configured = trim((string) config('company.legal_name', self::LEGAL_ENTITY));
        if ($configured === '' || self::isMisleadingPartnerWording($configured)) {
            return self::ISSUER_NAME;
        }

        if (strcasecmp($configured, self::LEGAL_ENTITY) === 0
            || strcasecmp($configured, self::ISSUER_NAME) === 0) {
            return self::ISSUER_NAME;
        }

        return $configured;
    }

    public static function footer(): string
    {
        $number = trim((string) (
            config('company.registration_no')
            ?: config('billing.company.registration_no')
            ?: self::COMPANY_NUMBER
        ));
        if ($number === '' || $number === self::COMPANY_NUMBER) {
            return self::FOOTER;
        }

        return self::ISSUER_NAME.'. Registered in England and Wales. Company number: '.$number.'.';
    }

    public static function isMisleadingPartnerWording(?string $value): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return false;
        }

        return str_contains($value, 'partners with')
            || str_contains($value, 'trading as')
            || str_contains($value, 'trading name of');
    }

    /**
     * Normalize a stored or configured seller string for customer invoices.
     */
    public static function normalizeLegalName(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || self::isMisleadingPartnerWording($value)) {
            return self::issuerName();
        }

        return $value;
    }
}
