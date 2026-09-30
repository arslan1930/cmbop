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
            'legalName' => $company['legal_name'] ?? 'SEOLinkBuildings Partners with (Teqno LTD)',
            'alternateName' => [
                'SEO Link Buildings',
                'Seolink Buildings',
                'Topurlz Ltd',
            ],
            'identifier' => $registrationNo,
            'url' => url('/'),
            'logo' => asset('assets/img/logo1.png'),
            'email' => $supportEmail,
            'sameAs' => self::sameAs($companiesHouse),
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

    /**
     * Page-level JSON-LD graph (Organization + WebPage) so crawlers detect
     * schema.org types on every public layout, including auth.
     *
     * @return array<string, mixed>
     */
    public static function pageGraph(string $name, string $description, string $url): array
    {
        $orgId = rtrim((string) url('/'), '/').'/#organization';
        $org = self::schema(['@id' => $orgId]);

        $page = [
            '@type' => 'WebPage',
            '@id' => $url.'#webpage',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'isPartOf' => ['@id' => $orgId],
            'about' => ['@id' => $orgId],
            'publisher' => ['@id' => $orgId],
        ];

        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'htmlLang')) {
            $page['inLanguage'] = PublicI18n::htmlLang();
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$org, $page],
        ];
    }

    /**
     * Leftover-safe JSON-LD for a <script type="application/ld+json"> block.
     * HEX_TAG stops </script> in titles/copy from breaking the page.
     *
     * @param  array<string, mixed>  $data
     */
    public static function jsonLd(array $data): string
    {
        try {
            $json = json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (\Throwable) {
            return '';
        }

        return is_string($json) && $json !== '' && $json !== 'null' && $json !== 'false' ? $json : '';
    }

    public static function pageGraphJson(string $name, string $description, string $url): string
    {
        try {
            return self::jsonLd(self::pageGraph($name, $description, $url));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Official identities for brand SERP: social profiles, Trustpilot, Companies House.
     *
     * @return list<string>
     */
    public static function sameAs(?string $companiesHouse = null): array
    {
        $company = config('billing.company', []);
        $registrationNo = (string) ($company['registration_no'] ?? '16607074');
        $companiesHouse ??= 'https://find-and-update.company-information.service.gov.uk/company/'.$registrationNo;
        $trustpilot = trim((string) config('services.trustpilot.review_url', ''));

        return array_values(array_unique(array_filter(array_merge(
            array_column(config('social.profiles', []), 'url'),
            [$companiesHouse, $trustpilot]
        ))));
    }
}
