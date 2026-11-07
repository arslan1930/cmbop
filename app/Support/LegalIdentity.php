<?php

namespace App\Support;

/**
 * Canonical issuer / brand copy for invoices and transactional mail.
 *
 * Companies House entity is Teqno Ltd (17392540). SEOLinkBuildings is the
 * trading name. Leftover "Partners with (Teqno LTD)" is not a legal name.
 *
 * VAT non-registration is whatever billing config currently says — this class
 * does not invent or change tax status.
 */
class LegalIdentity
{
    public const LEGAL_ENTITY = 'Teqno Ltd';

    public const TRADING_NAME = 'SEOLinkBuildings';

    public const COMPANY_NUMBER = '17392540';

    public const ISSUER_NAME = 'Teqno Ltd trading as SEOLinkBuildings';

    public const FOOTER = 'SEOLinkBuildings is a trading name of Teqno Ltd. Registered in England and Wales. Company number: 17392540.';

    public static function issuerName(): string
    {
        $configured = trim((string) config('company.legal_name', self::LEGAL_ENTITY));
        $entity = ($configured !== '' && ! self::isMisleadingPartnerWording($configured))
            ? $configured
            : self::LEGAL_ENTITY;

        if (strcasecmp($entity, self::LEGAL_ENTITY) === 0) {
            return self::ISSUER_NAME;
        }

        return $entity.' trading as '.self::TRADING_NAME;
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

        return self::TRADING_NAME.' is a trading name of '.self::LEGAL_ENTITY
            .'. Registered in England and Wales. Company number: '.$number.'.';
    }

    public static function isMisleadingPartnerWording(?string $value): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return false;
        }

        return str_contains($value, 'partners with')
            || str_contains($value, 'partners seolinkbuildings')
            || str_contains($value, '(partners seolinkbuildings)');
    }

    /**
     * Normalize a stored or configured seller string for customer documents.
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
