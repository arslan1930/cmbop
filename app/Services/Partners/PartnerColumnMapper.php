<?php

namespace App\Services\Partners;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Support\MarketplaceHttpUrl;
use App\Support\PublicationDuration;

class PartnerColumnMapper
{
    /** @var array<string, string> */
    public const ALIASES = [
        'contact_email' => 'email',
        'email' => 'email',
        'contact_name' => 'name',
        'name' => 'name',
        'publisher_name' => 'name',
        'site_url' => 'url',
        'url' => 'url',
        'website' => 'url',
        'website_url' => 'url',
        'siteurl' => 'url',
        'price' => 'price',
        'partner_rate' => 'price',
        'currency' => 'currency',
        'country' => 'country',
        'language' => 'language',
        'da' => 'da',
        'dr' => 'dr',
        'traffic' => 'traffic',
        'monthly_traffic' => 'traffic',
        'site_name' => 'site_name',
        'sitename' => 'site_name',
        'example_url' => 'example_url',
        'example_sponsored_post' => 'example_url',
        'sample_url' => 'example_url',
        'turnaround' => 'turnaround',
        'turnaround_days' => 'turnaround',
        'turnaround_time' => 'turnaround',
        'publication' => 'publication',
        'publication_length' => 'publication',
        'publication_time' => 'publication',
        'link_type' => 'link_type',
        'tag' => 'tag',
        'sponsored_tag' => 'tag',
        'sponsored_label' => 'tag',
        'niches' => 'niches',
        'niche' => 'niches',
        'categories' => 'niches',
        'category' => 'niches',
        'description' => 'description',
        'writing_fee' => 'writing_fee',
        'sensitive_extra_price' => 'sensitive_extra',
        'sensitive_extra' => 'sensitive_extra',
        'price_crypto' => 'sensitive_extra',
        'price_cbd' => 'sensitive_extra',
    ];

    /**
     * @param  array<int|string, mixed>  $headers
     * @return array<int, string>
     */
    public function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $i => $header) {
            $key = $this->alias((string) $header);
            $out[(int) $i] = $key ?? '';
        }

        return $out;
    }

    public function alias(string $header): ?string
    {
        $key = strtolower(trim($header));
        $key = str_replace([' ', '-'], '_', $key);

        return self::ALIASES[$key] ?? null;
    }

    /**
     * @param  array<int|string, mixed>  $cols
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    public function rowFromMapped(array $cols, array $headers, int $line): array
    {
        $fields = ['_line' => (string) $line];
        foreach ($headers as $i => $key) {
            if ($key === '') {
                continue;
            }
            $fields[$key] = trim((string) ($cols[$i] ?? ''));
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    public function rowFromAssoc(array $record, int $line): array
    {
        $fields = ['_line' => (string) $line];
        foreach ($record as $key => $value) {
            $alias = $this->alias((string) $key);
            if ($alias === null) {
                continue;
            }
            if (is_array($value)) {
                $value = implode('|', array_map(fn ($v) => trim((string) $v), $value));
            }
            $fields[$alias] = trim((string) $value);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{errors: list<string>, reason_code: ?string, blocked: bool, flags: list<string>, row: array<string, mixed>}
     */
    public function normalize(array $fields, int $line): array
    {
        $errors = [];
        $flags = [];
        $reasonCode = null;
        $blocked = false;

        $siteUrl = MarketplaceHttpUrl::normalize((string) ($fields['url'] ?? $fields['site_url'] ?? ''));
        $exampleUrl = MarketplaceHttpUrl::normalize((string) ($fields['example_url'] ?? ''));
        $host = is_string(parse_url($siteUrl, PHP_URL_HOST)) ? (string) parse_url($siteUrl, PHP_URL_HOST) : '';
        $domain = $host !== '' ? Site::normalizeMarketplaceDomain($host) : '';
        if ($siteUrl === '' || $domain === '' || ! MarketplaceHttpUrl::isMarketplaceHost($domain)) {
            $errors[] = 'Site URL is not a valid http(s) listing URL.';
            $reasonCode = 'invalid_url';
        }

        $exampleHost = is_string(parse_url($exampleUrl, PHP_URL_HOST)) ? (string) parse_url($exampleUrl, PHP_URL_HOST) : '';
        $exampleDomain = $exampleHost !== '' ? Site::normalizeMarketplaceDomain($exampleHost) : '';
        if ($exampleUrl !== '' && $domain !== '' && $exampleDomain !== '' && $exampleDomain !== $domain) {
            $errors[] = 'Sample post must be on the same domain.';
            $reasonCode ??= 'sample_off_domain';
        }

        $currency = strtoupper(trim((string) ($fields['currency'] ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }
        $allowed = array_map('strtoupper', (array) config('partners.currencies', ['EUR', 'CZK', 'PLN']));
        if (! in_array($currency, $allowed, true)) {
            $errors[] = 'Currency must be EUR, CZK, or PLN.';
            $reasonCode = 'unsupported_currency';
        }

        $price = $this->parseNumber($fields['price'] ?? null);
        if ($price === null || $price < 0) {
            $errors[] = 'Price is required.';
            $reasonCode ??= 'missing_required_field';
        }

        $writing = $this->parseNumber($fields['writing_fee'] ?? null);
        $sensitive = $this->parseNumber($fields['sensitive_extra'] ?? null);

        $da = $this->parseInt($fields['da'] ?? null, 0, 100);
        $dr = $this->parseInt($fields['dr'] ?? null, 0, 100);
        $traffic = $this->parseInt($fields['traffic'] ?? null, 0, 4294967295);
        if ($da === null) {
            $errors[] = 'DA must be from 0 to 100.';
            $reasonCode ??= 'missing_required_field';
        }
        if ($dr === null) {
            $errors[] = 'DR must be from 0 to 100.';
            $reasonCode ??= 'missing_required_field';
        }
        if ($traffic === null) {
            $errors[] = 'Traffic must be a whole number.';
            $reasonCode ??= 'missing_required_field';
        }

        $country = $this->resolveCountry((string) ($fields['country'] ?? ''));
        $language = $this->resolveLanguage((string) ($fields['language'] ?? ''));
        if ($country === null) {
            $errors[] = 'Country is required.';
            $reasonCode ??= 'missing_required_field';
        }
        if ($language === null) {
            $errors[] = 'Language is required.';
            $reasonCode ??= 'missing_required_field';
        }

        $nicheParts = $this->splitNiches((string) ($fields['niches'] ?? ''));
        if (count($nicheParts) > 7) {
            $nicheParts = array_slice($nicheParts, 0, 7);
            $flags[] = 'niches_truncated';
        }
        $resolved = Category::resolveNicheNames($nicheParts);
        $rawNiches = $resolved['unknown'];
        $niches = $resolved['resolved'];
        $unmapped = $rawNiches !== [];
        if ($unmapped) {
            $flags[] = 'unmapped_niche';
        }
        if ($niches === [] && $rawNiches === []) {
            $errors[] = 'At least one niche is required.';
            $reasonCode ??= 'missing_required_field';
        }

        $turnaround = $this->normalizeTurnaround((string) ($fields['turnaround'] ?? ''));
        if ($turnaround === null) {
            $errors[] = 'Turnaround is required.';
            $reasonCode ??= 'missing_required_field';
        }

        $publication = PublicationDuration::normalize((string) ($fields['publication'] ?? ''))
            ?? PublicationDuration::normalize(str_replace('_', '', (string) ($fields['publication'] ?? '')));
        if ($publication === null && trim((string) ($fields['publication'] ?? '')) !== '') {
            $rawPub = strtolower(trim((string) $fields['publication']));
            $publication = match (true) {
                str_contains($rawPub, 'permanent') => PublicationDuration::PERMANENT,
                str_contains($rawPub, '24') || str_contains($rawPub, '2 year') => PublicationDuration::TWO_YEARS,
                str_contains($rawPub, '12') || str_contains($rawPub, '1 year') => PublicationDuration::ONE_YEAR,
                str_contains($rawPub, '6') => PublicationDuration::SIX_MONTHS,
                default => null,
            };
        }
        if ($publication === null) {
            $errors[] = 'Publication length is required.';
            $reasonCode ??= 'missing_required_field';
        }

        $linkType = strtolower(trim((string) ($fields['link_type'] ?? '')));
        if (! in_array($linkType, ['dofollow', 'nofollow'], true)) {
            $errors[] = 'Link type must be dofollow or nofollow.';
            $reasonCode ??= 'missing_required_field';
        }

        $tag = $this->normalizeSponsored((string) ($fields['tag'] ?? ''));
        $siteName = trim((string) ($fields['site_name'] ?? ''));
        if ($siteName === '' && $domain !== '') {
            $siteName = $domain;
        }
        if ($siteName === '') {
            $errors[] = 'Site name is required.';
            $reasonCode ??= 'missing_required_field';
        }

        $description = trim((string) ($fields['description'] ?? ''));
        if (mb_strlen($description) > 1000) {
            $description = mb_substr($description, 0, 1000);
            $flags[] = 'description_truncated';
        }

        $casino = PartnerCasino::detect($siteName, $description, $exampleUrl, $niches, array_merge($rawNiches, $nicheParts));
        if ($casino) {
            $blocked = true;
            $reasonCode = 'casino_not_allowed';
            $errors[] = 'Casino listings are not accepted on the marketplace.';
        }

        return [
            'errors' => $errors,
            'reason_code' => $reasonCode,
            'blocked' => $blocked,
            'flags' => $flags,
            'row' => [
                '_line' => $line,
                'email' => strtolower(trim((string) ($fields['email'] ?? ''))),
                'name' => trim((string) ($fields['name'] ?? '')),
                'site_url' => $siteUrl,
                'domain' => $domain,
                'example_url' => $exampleUrl !== '' ? $exampleUrl : null,
                'site_name' => $siteName,
                'description' => $description,
                'country' => $country,
                'language' => $language,
                'niches' => $niches,
                'raw_niches' => $rawNiches,
                'da' => $da ?? 0,
                'dr' => $dr ?? 0,
                'traffic' => $traffic ?? 0,
                'price' => $price,
                'currency' => $currency,
                'writing_fee' => $writing,
                'sensitive_extra' => $sensitive,
                'turnaround_days' => $turnaround['days'] ?? null,
                'turnaround_time' => $turnaround['slug'] ?? null,
                'publication_time' => $publication,
                'link_type' => $linkType,
                'sponsored_label' => $tag,
                'casino' => $casino,
                'unmapped_niche' => $unmapped,
                'niches_truncated' => in_array('niches_truncated', $flags, true),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function splitNiches(string $raw): array
    {
        $parts = preg_split('/[;|,]/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @return array{days: int, slug: string}|null
     */
    private function normalizeTurnaround(string $raw): ?array
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }
        $map = [
            '24h' => ['days' => 1, 'slug' => '24h'],
            '24 hours' => ['days' => 1, 'slug' => '24h'],
            '1' => ['days' => 1, 'slug' => '24h'],
            '48h' => ['days' => 2, 'slug' => '48h'],
            '48 hours' => ['days' => 2, 'slug' => '48h'],
            '2' => ['days' => 2, 'slug' => '48h'],
            '3days' => ['days' => 3, 'slug' => '3days'],
            '3 days' => ['days' => 3, 'slug' => '3days'],
            '3' => ['days' => 3, 'slug' => '3days'],
            '5days' => ['days' => 5, 'slug' => '5days'],
            '5 days' => ['days' => 5, 'slug' => '5days'],
            '5' => ['days' => 5, 'slug' => '5days'],
            '7days' => ['days' => 7, 'slug' => '7days'],
            '7 days' => ['days' => 7, 'slug' => '7days'],
            '7' => ['days' => 7, 'slug' => '7days'],
        ];
        if (isset($map[$value])) {
            return $map[$value];
        }
        if (is_numeric($value)) {
            $days = max(1, min(30, (int) $value));
            $slug = match (true) {
                $days <= 1 => '24h',
                $days <= 2 => '48h',
                $days <= 3 => '3days',
                $days <= 5 => '5days',
                default => '7days',
            };

            return ['days' => $days, 'slug' => $slug];
        }

        return null;
    }

    private function normalizeSponsored(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'never', 'no', 'none' => 'never',
            'sometimes' => 'sometimes',
            'always', 'yes', 'sponsored' => 'always',
            'partner_material' => 'partner_material',
            'as_you_prefer' => 'as_you_prefer',
            default => $value,
        };
    }

    private function parseNumber(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return round((float) $raw, 2);
        }
        $s = trim((string) $raw);
        $s = str_replace([' ', "\u{00a0}"], '', $s);
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s) === 1) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',') && ! str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }
        if (! is_numeric($s)) {
            return null;
        }

        return round((float) $s, 2);
    }

    private function parseInt(mixed $raw, int $min, int $max): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $n = (int) $raw;
        if ($n < $min || $n > $max) {
            return null;
        }

        return $n;
    }

    private function resolveCountry(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }
        if (strlen($value) === 2) {
            $code = $value === 'uk' ? 'gb' : $value;
            $exists = Country::query()->whereRaw('LOWER(code) = ?', [$code])->exists();

            return $exists ? $code : $code;
        }

        $row = Country::query()->whereRaw('LOWER(name) = ?', [$value])->first();

        return $row ? strtolower((string) $row->code) : null;
    }

    private function resolveLanguage(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }
        if (strlen($value) === 2) {
            return $value;
        }
        $aliases = [
            'czech' => 'cs',
            'polish' => 'pl',
            'english' => 'en',
            'german' => 'de',
            'slovak' => 'sk',
        ];
        if (isset($aliases[$value])) {
            return $aliases[$value];
        }
        $row = Language::query()->whereRaw('LOWER(name) = ?', [$value])->first();

        return $row ? strtolower((string) $row->code) : null;
    }
}
