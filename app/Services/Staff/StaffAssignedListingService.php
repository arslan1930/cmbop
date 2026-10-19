<?php

namespace App\Services\Staff;

use App\Models\Category;
use App\Models\Site;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\SiteDescriptionSanitizer;
use App\Support\PublicationDuration;
use App\Support\SiteDescriptionRules;
use App\Support\SiteTag;
use App\Support\StaffListingPublishMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Shared staff invite listing parser / validator / writer.
 * Used by admin site-bulk-create and managed publisher onboarding.
 */
class StaffAssignedListingService
{
    public const SITE_COLUMNS = [
        'url',
        'price',
        'da',
        'dr',
        'traffic',
        'country',
        'language',
        'site_name',
        'example_url',
        'turnaround',
        'publication',
        'link_type',
        'tag',
        'niches',
        'description',
    ];

    public const ONBOARDING_COLUMNS = [
        'email',
        'name',
        ...self::SITE_COLUMNS,
    ];

    /**
     * @return list<array<string, string>>
     */
    public function rowsFromPaste(string $raw): array
    {
        $rows = [];
        $lineNo = 0;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $lineNo++;
            $trimmed = trim($this->stripBom($line));
            if ($trimmed === '') {
                continue;
            }
            $delimiter = $this->detectDelimiter($trimmed, 'url');
            $parts = str_getcsv($trimmed, $delimiter, '"', '');
            if ($this->isSiteHeaderRow($parts)) {
                continue;
            }
            $rows[] = $this->siteFieldsFromColumns($parts, $delimiter, $lineNo);
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    public function rowsFromCsv(UploadedFile $file): array
    {
        return $this->readCsv($file, function (array $cols, string $delimiter, int $lineNo) {
            if ($this->isSiteHeaderRow($cols)) {
                return null;
            }

            return $this->siteFieldsFromColumns($cols, $delimiter, $lineNo);
        }, 'url');
    }

    /**
     * @return list<array<string, string>>
     */
    public function onboardingRowsFromPaste(string $raw): array
    {
        $rows = [];
        $lineNo = 0;
        $headers = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $lineNo++;
            $trimmed = trim($this->stripBom($line));
            if ($trimmed === '') {
                continue;
            }
            $delimiter = $this->detectDelimiter($trimmed, 'email');
            $parts = str_getcsv($trimmed, $delimiter, '"', '');
            if ($headers === null && $this->isOnboardingHeaderRow($parts)) {
                $headers = $this->normalizeOnboardingHeaders($parts);

                continue;
            }
            $rows[] = $headers === null
                ? $this->onboardingFieldsFromColumns($parts, $delimiter, $lineNo)
                : $this->onboardingFieldsFromMappedRow($parts, $headers, $lineNo);
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    public function onboardingRowsFromCsv(UploadedFile $file): array
    {
        $headers = null;

        return $this->readCsv($file, function (array $cols, string $delimiter, int $lineNo) use (&$headers) {
            if ($headers === null && $this->isOnboardingHeaderRow($cols)) {
                $headers = $this->normalizeOnboardingHeaders($cols);

                return null;
            }

            return $headers === null
                ? $this->onboardingFieldsFromColumns($cols, $delimiter, $lineNo)
                : $this->onboardingFieldsFromMappedRow($cols, $headers, $lineNo);
        }, 'email');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function fieldsFromStaffForm(array $input, int $line = 1): array
    {
        $categories = $input['categories'] ?? $input['category'] ?? '';
        if (is_array($categories)) {
            $categories = implode('|', array_map(fn ($v) => trim((string) $v), $categories));
        }

        return [
            '_line' => (string) $line,
            'email' => trim((string) ($input['email'] ?? '')),
            'name' => trim((string) ($input['name'] ?? $input['publisher_name'] ?? '')),
            'site_url' => (string) ($input['site_url'] ?? $input['url'] ?? ''),
            'price' => (string) ($input['price'] ?? ''),
            'da' => (string) ($input['da'] ?? ''),
            'dr' => (string) ($input['dr'] ?? ''),
            'traffic' => (string) ($input['traffic'] ?? ''),
            'country' => (string) ($input['country'] ?? ''),
            'language' => (string) ($input['language'] ?? ''),
            'site_name' => (string) ($input['site_name'] ?? ''),
            'example_url' => (string) ($input['example_url'] ?? ''),
            'turnaround_time' => (string) ($input['turnaround_time'] ?? $input['turnaround'] ?? ''),
            'publication_time' => (string) ($input['publication_time'] ?? $input['publication'] ?? ''),
            'link_type' => (string) ($input['link_type'] ?? ''),
            'site_tag' => (string) ($input['site_tag'] ?? $input['tag'] ?? ''),
            'categories' => (string) $categories,
            'description' => (string) ($input['description'] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<string>  $allowedCountries
     * @param  list<string>  $allowedLanguages
     * @param  array<string, true>  $seenDomains
     * @return array{line: int, errors: list<string>, row: array<string, mixed>}
     */
    public function validateRow(array $fields, int $line, array $allowedCountries, array $allowedLanguages, array &$seenDomains): array
    {
        $errors = [];
        $siteUrl = $this->normalizeHttpUrl((string) ($fields['site_url'] ?? ''));
        $exampleUrl = $this->normalizeHttpUrl((string) ($fields['example_url'] ?? ''));
        $domain = $this->domainFromUrl($siteUrl);
        $exampleDomain = $this->domainFromUrl($exampleUrl);
        if ($domain === null) {
            $errors[] = 'Invalid URL';
        }
        if ($exampleDomain === null || ($domain !== null && $this->exampleUrlHostDiffers($siteUrl, $exampleUrl))) {
            $errors[] = 'Example URL must be on the same website domain.';
        }
        if ($domain !== null) {
            if (isset($seenDomains[$domain])) {
                $errors[] = 'This domain is repeated in the batch.';
            }
            $seenDomains[$domain] = true;
            $existing = $this->findSiteByDomain($domain);
            if ($existing) {
                $errors[] = $existing->occupyingDomainMessage();
            }
        }

        $siteName = $this->normalizeSiteName((string) ($fields['site_name'] ?? ''));
        if ($siteName === '' || strlen($siteName) > 255) {
            $errors[] = 'Site name is required.';
        }
        $price = is_numeric($fields['price'] ?? null) ? round((float) $fields['price'], 2) : null;
        if ($price === null || $price < 0 || $price > 999999.99) {
            $errors[] = 'Price must be from 0 to 999999.99.';
        }
        $da = is_numeric($fields['da'] ?? null) ? (int) $fields['da'] : null;
        $dr = is_numeric($fields['dr'] ?? null) ? (int) $fields['dr'] : null;
        $traffic = is_numeric($fields['traffic'] ?? null) ? (int) $fields['traffic'] : null;
        if ($da === null || $da < 0 || $da > 100) {
            $errors[] = 'DA must be from 0 to 100.';
        }
        if ($dr === null || $dr < 0 || $dr > 100) {
            $errors[] = 'DR must be from 0 to 100.';
        }
        if ($traffic === null || $traffic < 0 || $traffic > 4294967295) {
            $errors[] = 'Traffic must be a whole number.';
        }

        $country = strtolower(trim((string) ($fields['country'] ?? '')));
        $language = strtolower(trim((string) ($fields['language'] ?? '')));
        if (! in_array($country, $allowedCountries, true)) {
            $errors[] = 'Country is not a marketplace country.';
        }
        if (! in_array($language, $allowedLanguages, true)) {
            $errors[] = 'Language is not a marketplace language.';
        }
        if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
            $errors[] = 'That language is not allowed for the selected country.';
        }

        $resolved = Category::resolveNicheNames($this->nicheNamesInput($fields['categories'] ?? ''));
        if ($resolved['unknown'] !== []) {
            $errors[] = 'Unknown niche: '.implode(', ', $resolved['unknown']);
        }
        if ($resolved['resolved'] === [] || count($resolved['resolved']) > 7) {
            $errors[] = 'Choose 1 to 7 niches.';
        }

        $turnaround = strtolower(trim((string) ($fields['turnaround_time'] ?? '')));
        $publication = strtolower(trim((string) ($fields['publication_time'] ?? '')));
        $linkType = strtolower(trim((string) ($fields['link_type'] ?? '')));
        if (! in_array($turnaround, ['24h', '48h', '3days', '5days', '7days'], true)) {
            $errors[] = 'Turnaround must be 24h, 48h, 3days, 5days, or 7days.';
        }
        $publication = PublicationDuration::normalize($publication) ?? $publication;
        if (! in_array($publication, PublicationDuration::values(), true)) {
            $errors[] = 'Publication must be '.PublicationDuration::csvHint().'.';
        }
        if (! in_array($linkType, ['dofollow', 'nofollow'], true)) {
            $errors[] = 'Link type must be dofollow or nofollow.';
        }
        $tag = strtolower(trim((string) ($fields['site_tag'] ?? '')));
        if ($tag !== '' && ! in_array($tag, ['sponsored', 'partner_material', 'as_you_prefer', 'none'], true)) {
            $errors[] = 'Tag must be sponsored, partner_material, as_you_prefer, none, or blank.';
        }

        foreach (SiteDescriptionRules::errors((string) ($fields['description'] ?? '')) as $message) {
            $errors[] = $message;
        }

        return [
            'line' => $line,
            'errors' => $errors,
            'row' => [
                'email' => strtolower(trim((string) ($fields['email'] ?? ''))),
                'name' => trim((string) ($fields['name'] ?? '')),
                'site_name' => $siteName,
                'site_url' => $siteUrl,
                'example_url' => $exampleUrl,
                'domain' => $domain,
                'da' => $da,
                'dr' => $dr,
                'traffic' => $traffic,
                'country' => $country,
                'language' => $language,
                'categories' => $resolved['resolved'],
                'price' => $price,
                'turnaround_time' => $turnaround,
                'publication_time' => $publication,
                'link_type' => $linkType,
                'site_tag' => $tag === '' ? null : $tag,
                'description' => (string) ($fields['description'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function persistInvite(array $row, int $publisherId, string $publishMode = StaffListingPublishMode::INVITE, ?int $assignedByUserId = null): Site
    {
        $assignedByUserId = $assignedByUserId ?? (int) auth()->id();
        $domain = (string) $row['domain'];
        Site::releaseCancelledBulkDomain($domain, $publisherId);
        $existing = $this->findSiteByDomain($domain, lock: true);
        if ($existing) {
            throw ValidationException::withMessages([
                'rows' => [$existing->occupyingDomainMessage()],
            ]);
        }

        $categories = $row['categories'];
        $cleanDescription = app(SiteDescriptionSanitizer::class)->sanitize((string) $row['description']);
        $modeAttrs = StaffListingPublishMode::staffAssignAttributes($publishMode);
        $site = new Site;
        $site->applyMarketplaceListing(array_merge([
            'publisher_id' => $publisherId,
            'assigned_by_user_id' => $assignedByUserId,
            'site_name' => $row['site_name'],
            'site_url' => $row['site_url'],
            'domain' => $domain,
            'example_url' => $row['example_url'],
            'da' => $row['da'],
            'dr' => $row['dr'],
            'traffic' => $row['traffic'],
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'country' => $row['country'],
            'countries' => [$row['country']],
            'language' => $row['language'],
            'languages' => [$row['language']],
            'category' => implode('|', $categories),
            'categories' => $categories,
            'price' => $row['price'],
            'turnaround_time' => $row['turnaround_time'],
            'publication_time' => $row['publication_time'],
            'link_type' => $row['link_type'],
            'description' => $cleanDescription,
            'enrichment_status' => 'pending',
        ], $modeAttrs));
        $site->forceFill(array_merge([
            'assigned_by_user_id' => $assignedByUserId,
            'da' => $row['da'],
            'dr' => $row['dr'],
            'traffic' => $row['traffic'],
            'price' => $row['price'],
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
        ], $modeAttrs));
        SiteTag::applyStaffDefault($site, $row['site_tag'] ?? null);
        $site->save();

        if ((int) $site->da !== (int) $row['da'] || (int) $site->dr !== (int) $row['dr'] || (int) $site->traffic !== (int) $row['traffic']) {
            throw new \RuntimeException('DA/DR/traffic did not persist after save.');
        }
        if (is_numeric($row['price']) && round((float) $site->price, 2) !== round((float) $row['price'], 2)) {
            throw new \RuntimeException('Staff site price did not persist after save.');
        }
        if (blank($site->assigned_by_user_id)) {
            throw new \RuntimeException('Publisher invite state did not persist after save.');
        }
        if (StaffListingPublishMode::isPublish($publishMode)) {
            if (! (bool) $site->active || (bool) $site->verified
                || ! ($site->publisher_accepted_at instanceof \DateTimeInterface)) {
                throw new \RuntimeException('Staff site publish-now flags did not persist after save.');
            }
        } elseif (filled($site->publisher_accepted_at) || (bool) $site->verified || (bool) $site->active) {
            throw new \RuntimeException('Publisher invite state did not persist after save.');
        }

        return $site;
    }

    /**
     * @param  callable(list<mixed>, string, int): (?array<string, string>)  $mapper
     * @return list<array<string, string>>
     */
    private function readCsv(UploadedFile $file, callable $mapper, string $hint): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        $start = ftell($handle);
        $sample = '';
        $sampleLine = fgets($handle);
        if (is_string($sampleLine)) {
            $sample = $this->stripBom($sampleLine);
        }
        if ($start !== false) {
            fseek($handle, $start);
        }
        $delimiter = $this->detectDelimiter($sample, $hint);
        $rows = [];
        $lineNo = 0;
        while (($cols = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $lineNo++;
            if ($cols === [null]) {
                continue;
            }
            $cols[0] = $this->stripBom((string) ($cols[0] ?? ''));
            if (implode('', array_map(fn ($value) => trim((string) $value), $cols)) === '') {
                continue;
            }
            $mapped = $mapper($cols, $delimiter, $lineNo);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<mixed>  $cols
     */
    private function isSiteHeaderRow(array $cols): bool
    {
        return strtolower(trim($this->stripBom((string) ($cols[0] ?? '')))) === 'url';
    }

    /**
     * @param  list<mixed>  $cols
     */
    private function isOnboardingHeaderRow(array $cols): bool
    {
        $first = $this->headerAlias((string) ($cols[0] ?? ''));

        return $first === 'email';
    }

    /**
     * @param  list<mixed>  $cols
     * @return list<string>
     */
    private function normalizeOnboardingHeaders(array $cols): array
    {
        return array_map(fn ($col) => $this->headerAlias((string) $col), $cols);
    }

    private function headerAlias(string $raw): string
    {
        $key = strtolower(trim($this->stripBom($raw)));
        $key = str_replace([' ', '-'], '_', $key);

        return match ($key) {
            'site_url', 'website', 'website_url' => 'url',
            'publisher_name', 'full_name' => 'name',
            'turnaround_time' => 'turnaround',
            'publication_time' => 'publication',
            'site_tag' => 'tag',
            'categories', 'niche' => 'niches',
            'example' => 'example_url',
            default => $key,
        };
    }

    private function detectDelimiter(string $line, string $hint): string
    {
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t"] as $delimiter) {
            if ($delimiter !== ',' && substr_count($line, $delimiter) === 0) {
                continue;
            }
            $parts = str_getcsv($line, $delimiter, '"', '');
            $first = strtolower(trim((string) ($parts[0] ?? '')));
            $score = min(count($parts), 20) + min(substr_count($line, $delimiter), 20);
            if ($first === $hint || str_starts_with($first, 'http') || $first === 'url' || $first === 'email') {
                $score += 4;
            }
            if (filter_var($first, FILTER_VALIDATE_EMAIL)) {
                $score += 4;
            }
            if (is_numeric(trim((string) ($parts[1] ?? ''))) || is_numeric(trim((string) ($parts[3] ?? '')))) {
                $score += 2;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * @param  list<mixed>  $cols
     * @return array<string, string>
     */
    private function siteFieldsFromColumns(array $cols, string $delimiter, int $line): array
    {
        $raw = array_map(fn ($value) => (string) $value, $cols);
        $cols = array_map('trim', $raw);
        $niches = $cols[13] ?? '';
        $description = $cols[14] ?? '';
        if (count($raw) > 15) {
            [$niches, $description] = $this->repairNicheAndDescription(array_slice($raw, 13), $delimiter);
        }

        return [
            '_line' => (string) $line,
            'site_url' => $cols[0] ?? '',
            'price' => $cols[1] ?? '',
            'da' => $cols[2] ?? '',
            'dr' => $cols[3] ?? '',
            'traffic' => $cols[4] ?? '',
            'country' => $cols[5] ?? '',
            'language' => $cols[6] ?? '',
            'site_name' => $cols[7] ?? '',
            'example_url' => $cols[8] ?? '',
            'turnaround_time' => $cols[9] ?? '',
            'publication_time' => $cols[10] ?? '',
            'link_type' => $cols[11] ?? '',
            'site_tag' => $cols[12] ?? '',
            'categories' => $niches,
            'description' => $description,
        ];
    }

    /**
     * @param  list<mixed>  $cols
     * @return array<string, string>
     */
    private function onboardingFieldsFromColumns(array $cols, string $delimiter, int $line): array
    {
        $raw = array_map(fn ($value) => (string) $value, $cols);
        $cols = array_map('trim', $raw);
        $niches = $cols[15] ?? '';
        $description = $cols[16] ?? '';
        if (count($raw) > 17) {
            [$niches, $description] = $this->repairNicheAndDescription(array_slice($raw, 15), $delimiter);
        }

        return [
            '_line' => (string) $line,
            'email' => $cols[0] ?? '',
            'name' => $cols[1] ?? '',
            'site_url' => $cols[2] ?? '',
            'price' => $cols[3] ?? '',
            'country' => $cols[4] ?? '',
            'language' => $cols[5] ?? '',
            'da' => $cols[6] ?? '',
            'dr' => $cols[7] ?? '',
            'traffic' => $cols[8] ?? '',
            'site_name' => $cols[9] ?? '',
            'example_url' => $cols[10] ?? '',
            'turnaround_time' => $cols[11] ?? '',
            'publication_time' => $cols[12] ?? '',
            'link_type' => $cols[13] ?? '',
            'site_tag' => $cols[14] ?? '',
            'categories' => $niches,
            'description' => $description,
        ];
    }

    /**
     * @param  list<mixed>  $cols
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    private function onboardingFieldsFromMappedRow(array $cols, array $headers, int $line): array
    {
        $mapped = [];
        foreach ($headers as $index => $header) {
            $mapped[$header] = trim((string) ($cols[$index] ?? ''));
        }

        return [
            '_line' => (string) $line,
            'email' => $mapped['email'] ?? '',
            'name' => $mapped['name'] ?? '',
            'site_url' => $mapped['url'] ?? '',
            'price' => $mapped['price'] ?? '',
            'country' => $mapped['country'] ?? '',
            'language' => $mapped['language'] ?? '',
            'da' => $mapped['da'] ?? '',
            'dr' => $mapped['dr'] ?? '',
            'traffic' => $mapped['traffic'] ?? '',
            'site_name' => $mapped['site_name'] ?? '',
            'example_url' => $mapped['example_url'] ?? '',
            'turnaround_time' => $mapped['turnaround'] ?? '',
            'publication_time' => $mapped['publication'] ?? '',
            'link_type' => $mapped['link_type'] ?? '',
            'site_tag' => $mapped['tag'] ?? '',
            'categories' => $mapped['niches'] ?? '',
            'description' => $mapped['description'] ?? '',
        ];
    }

    /**
     * @param  list<string>  $tail
     * @return array{0: string, 1: string}
     */
    private function repairNicheAndDescription(array $tail, string $delimiter): array
    {
        $tail = array_values($tail);
        $count = count($tail);
        for ($take = 1; $take <= $count; $take++) {
            $niche = trim(implode($delimiter, array_slice($tail, 0, $take)));
            $description = trim(implode($delimiter, array_slice($tail, $take)));
            $resolved = Category::resolveNicheNames($this->nicheNamesInput($niche));
            if ($resolved['unknown'] !== [] || $resolved['resolved'] === [] || count($resolved['resolved']) > 7) {
                continue;
            }
            if (SiteDescriptionRules::errors($description) !== []) {
                continue;
            }

            return [$niche, $description];
        }

        return [
            trim(implode($delimiter, array_slice($tail, 0, 1))),
            trim(implode($delimiter, array_slice($tail, 1))),
        ];
    }

    private function stripBom(string $value): string
    {
        return ltrim($value, "\xEF\xBB\xBF");
    }

    private function domainFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $domain = $this->normalizeDomain($host);
        if ($domain === '' || ! $this->isMarketplaceHost($domain)) {
            return null;
        }

        return $domain;
    }

    private function normalizeDomain(string $host): string
    {
        return Site::normalizeMarketplaceDomain($host);
    }

    private function isMarketplaceHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_starts_with($host, '[')) {
            return false;
        }
        if (str_contains($host, ':') && preg_match('/^(.+):(\d+)$/', $host, $m) === 1) {
            $host = $m[1];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);
        $tld = (string) end($labels);
        if (in_array($tld, ['localhost', 'local', 'internal', 'invalid'], true)) {
            return false;
        }
        $allNumeric = true;
        foreach ($labels as $label) {
            if ($label === '') {
                return false;
            }
            if (! ctype_digit($label)) {
                $allNumeric = false;
            }
        }

        return ! $allNumeric;
    }

    private function normalizeSiteName(string $raw): string
    {
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $raw) ?? $raw;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function urlHost(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $this->normalizeDomain($host) : '';
    }

    private function exampleUrlHostDiffers(mixed $siteUrl, mixed $exampleUrl): bool
    {
        $siteHost = $this->urlHost($siteUrl);
        $exampleHost = $this->urlHost($exampleUrl);

        return $siteHost !== '' && $exampleHost !== '' && $siteHost !== $exampleHost;
    }

    /**
     * @return string|iterable<int|string, mixed>|null
     */
    private function nicheNamesInput(mixed $raw): string|iterable|null
    {
        if (is_string($raw) || is_iterable($raw) || $raw === null) {
            return $raw;
        }

        return [];
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\0") || preg_match('/\s/u', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (preg_match('~^(?:https?|ftps?)://~i', $url) !== 1) {
            if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $schemeMatch) === 1) {
                $scheme = strtolower($schemeMatch[1]);
                $hasAuthority = preg_match('~^'.preg_quote($schemeMatch[1], '~').'://~i', $url) === 1;
                if ($hasAuthority || in_array($scheme, ['javascript', 'data', 'mailto', 'vbscript', 'file', 'about', 'blob'], true)) {
                    return '';
                }
                if (! str_contains($scheme, '.')) {
                    return '';
                }
            }
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = rtrim($parts['host'], '.');
        if ($host === '') {
            return '';
        }
        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP) && ! str_starts_with($host, '[')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
            $host = strtolower($host);
        }
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $authority = $host;
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            if ($port < 1 || $port > 65535) {
                return '';
            }
            if (! in_array($port, [80, 443], true)) {
                $authority .= ':'.$port;
            }
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$authority.$path.$query;
    }

    private function findSiteByDomain(string $domain, bool $lock = false): ?Site
    {
        return Site::findOccupyingDomain($domain, null, $lock);
    }
}
