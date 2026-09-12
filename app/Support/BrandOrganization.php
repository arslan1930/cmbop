<?php

namespace App\Support;

class BrandOrganization
{
    /**
     * Shared Organization JSON-LD. Brand misspellings stay in alternateName;
     * visible titles keep the canonical SEOLinkBuildings spelling and NAP.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function schema(array $extra = []): array
    {
        $company = config('billing.company', []);
        $registrationNo = (string) ($company['registration_no'] ?? '16607074');
        $supportEmail = trim((string) ($company['support_email'] ?? ''));
        if ($supportEmail === '') {
            $supportEmail = 'support@seolinkbuildings.com';
        }

        $companiesHouse = 'https://find-and-update.company-information.service.gov.uk/company/'.$registrationNo;

        return array_merge([
            '@type' => 'Organization',
            'name' => 'SEOLinkBuildings',
            'legalName' => $company['legal_name'] ?? 'SEOLinkBuildings Partners with (Topurlz LTD)',
            'alternateName' => [
                'SEO Link Buildings',
                'Seolink Buildings',
                'Topurlz Ltd',
            ],
            'identifier' => $registrationNo,
            'url' => url('/'),
            'logo' => asset('assets/img/logo1.png'),
            'email' => $supportEmail,
            'sameAs' => array_values(array_filter(array_merge(
                array_column(config('social.profiles', []), 'url'),
                [$companiesHouse]
            ))),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '20 Wenlock Road',
                'addressLocality' => 'London',
                'postalCode' => 'N1 7GU',
                'addressCountry' => 'GB',
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => $supportEmail,
            ],
        ], $extra);
    }
}
