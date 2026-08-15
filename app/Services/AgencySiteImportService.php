<?php

namespace App\Services;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\AgencySiteImport;
use App\Models\AgencySiteImportFailure;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketplace\CountryLanguagePairs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AgencySiteImportService
{
    /**
     * @return array{
     *   import: ?AgencySiteImport,
     *   created: int,
     *   would_create: int,
     *   failed: list<array{row:int,site:string,errors:list<string>}>,
     *   processed: int,
     *   dry_run: bool
     * }
     */
    public function importFromUpload(User $publisher, UploadedFile $file, bool $dryRun = false): array
    {
        $maxRows = AgencySiteImport::MAX_ROWS;
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new InvalidArgumentException('Could not read the uploaded file.');
        }

        try {
            // Skip UTF-8 BOM if present
            $firstBytes = fread($handle, 3);
            if ($firstBytes !== chr(0xEF).chr(0xBB).chr(0xBF)) {
                rewind($handle);
            }

            $headerRow = fgetcsv($handle);
            if (! $headerRow) {
                throw new InvalidArgumentException('CSV is empty.');
            }

            $headers = array_map(function ($h) {
                return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
            }, $headerRow);

            $requiredHeaders = [
                'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic',
                'categories', 'price', 'turnaround_time',
                'publication_time', 'link_type', 'description',
            ];

            $hasCountries = in_array('countries', $headers, true) || in_array('country', $headers, true);
            $hasLanguages = in_array('languages', $headers, true) || in_array('language', $headers, true);
            if (! $hasCountries || ! $hasLanguages) {
                throw new InvalidArgumentException(
                    'CSV must include countries (or country) and languages (or language) columns. Download the template and try again.'
                );
            }

            foreach ($requiredHeaders as $required) {
                if (! in_array($required, $headers, true)) {
                    throw new InvalidArgumentException(
                        "CSV is missing required column: {$required}. Download the template and try again."
                    );
                }
            }

            $validCategoryNames = Category::pluck('name')->map(fn ($n) => strtolower($n))->all();

            $import = null;
            if (! $dryRun) {
                $import = AgencySiteImport::create([
                    'publisher_id' => $publisher->id,
                    'status' => AgencySiteImport::STATUS_PROCESSING,
                    'original_filename' => $file->getClientOriginalName(),
                    'dry_run' => false,
                ]);
            }

            $created = 0;
            $wouldCreate = 0;
            $failed = [];
            /** @var list<array{row:int,site:string,site_name:?string,site_url:?string,errors:list<string>}> $failureRecords */
            $failureRecords = [];
            $seenDomainsInFile = [];
            $rowNumber = 1;
            $processed = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                if (($created + $wouldCreate + count($failed)) >= $maxRows) {
                    $failure = [
                        'row' => $rowNumber,
                        'site' => '',
                        'site_name' => null,
                        'site_url' => null,
                        'errors' => ["Maximum {$maxRows} rows per upload. Remaining rows were skipped."],
                    ];
                    $failed[] = [
                        'row' => $failure['row'],
                        'site' => $failure['site'],
                        'errors' => $failure['errors'],
                    ];
                    $failureRecords[] = $failure;

                    break;
                }

                if (count($row) < count($headers)) {
                    $row = array_pad($row, count($headers), '');
                }
                $data = array_combine($headers, array_slice($row, 0, count($headers)));
                if ($data === false) {
                    $failure = [
                        'row' => $rowNumber,
                        'site' => '',
                        'site_name' => null,
                        'site_url' => null,
                        'errors' => ['Could not parse row.'],
                    ];
                    $failed[] = [
                        'row' => $failure['row'],
                        'site' => $failure['site'],
                        'errors' => $failure['errors'],
                    ];
                    $failureRecords[] = $failure;

                    continue;
                }

                $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
                $processed++;

                if (($data['site_url'] ?? '') === 'https://example-agency-blog.com') {
                    continue;
                }

                $parsed = $this->normalizeBulkRow($data, $validCategoryNames);

                if (! empty($parsed['errors'])) {
                    $failure = [
                        'row' => $rowNumber,
                        'site' => $data['site_url'] ?? ($data['site_name'] ?? ''),
                        'site_name' => $data['site_name'] ?? null,
                        'site_url' => $data['site_url'] ?? null,
                        'errors' => $parsed['errors'],
                    ];
                    $failed[] = [
                        'row' => $failure['row'],
                        'site' => $failure['site'],
                        'errors' => $failure['errors'],
                    ];
                    $failureRecords[] = $failure;

                    continue;
                }

                $domain = $parsed['domain'];

                if (isset($seenDomainsInFile[$domain])) {
                    $failure = [
                        'row' => $rowNumber,
                        'site' => $data['site_url'],
                        'site_name' => $data['site_name'] ?? null,
                        'site_url' => $data['site_url'] ?? null,
                        'errors' => ["Duplicate domain in this file (also on row {$seenDomainsInFile[$domain]})."],
                    ];
                    $failed[] = [
                        'row' => $failure['row'],
                        'site' => $failure['site'],
                        'errors' => $failure['errors'],
                    ];
                    $failureRecords[] = $failure;

                    continue;
                }
                $seenDomainsInFile[$domain] = $rowNumber;

                $pending = BulkSiteRequestItem::occupyingPendingDomainMessage($domain);
                if (Site::findOccupyingDomain($domain) || $pending !== null) {
                    $failure = [
                        'row' => $rowNumber,
                        'site' => $data['site_url'],
                        'site_name' => $data['site_name'] ?? null,
                        'site_url' => $data['site_url'] ?? null,
                        'errors' => [$pending ?? 'This domain is already registered in the system.'],
                    ];
                    $failed[] = [
                        'row' => $failure['row'],
                        'site' => $failure['site'],
                        'errors' => $failure['errors'],
                    ];
                    $failureRecords[] = $failure;

                    continue;
                }

                if ($dryRun) {
                    $wouldCreate++;

                    continue;
                }

                try {
                    $site = null;
                    DB::transaction(function () use ($parsed, $publisher, $import, &$site) {
                        Site::releaseCancelledBulkDomain($parsed['domain'], (int) $publisher->id);
                        if (Site::findOccupyingDomain($parsed['domain'])) {
                            throw new InvalidArgumentException('This domain is already registered in the system.');
                        }
                        $pending = BulkSiteRequestItem::occupyingPendingDomainMessage($parsed['domain']);
                        if ($pending !== null) {
                            throw new InvalidArgumentException($pending);
                        }

                        $listing = [
                            'publisher_id' => $publisher->id,
                            'site_name' => $parsed['site_name'],
                            'site_url' => $parsed['site_url'],
                            'domain' => $parsed['domain'],
                            'example_url' => $parsed['example_url'],
                            'da' => $parsed['da'],
                            'dr' => $parsed['dr'],
                            'traffic' => $parsed['traffic'],
                            'metrics_manual' => true,
                            'metrics_provider' => 'manual',
                            'metrics_fetched_at' => now(),
                            'country' => $parsed['country'],
                            'countries' => $parsed['countries'],
                            'language' => $parsed['language'],
                            'languages' => $parsed['languages'],
                            'category' => $parsed['primary_category'],
                            'categories' => $parsed['categories'],
                            'price' => $parsed['price'],
                            'turnaround_time' => $parsed['turnaround_time'],
                            'publication_time' => $parsed['publication_time'],
                            'link_type' => $parsed['link_type'],
                            'sponsored' => $parsed['sponsored'],
                            'partner_material' => $parsed['partner_material'],
                            'as_you_prefer' => $parsed['as_you_prefer'],
                            'description' => $parsed['description'],
                            'sensitive_prices' => $parsed['sensitive_prices'],
                            'verified' => false,
                            'active' => false,
                            'enrichment_status' => 'pending',
                        ];

                        if (Site::hasSitesColumn('publisher_accepted_at')) {
                            $listing['publisher_accepted_at'] = now();
                        }
                        if (Site::hasSitesColumn('agency_site_import_id') && $import !== null) {
                            $listing['agency_site_import_id'] = $import->id;
                        }

                        $site = new Site;
                        $site->applyMarketplaceListing($listing);
                        $site->save();
                    });

                    if ($site !== null) {
                        ActivityLogger::log(
                            'site.bulk_imported',
                            ($publisher->name ?? 'Publisher').' bulk-imported site "'.$site->site_name.'" from agency CSV (row '.$rowNumber.')',
                            $site,
                            [
                                'import_id' => $import?->id,
                                'row' => $rowNumber,
                            ],
                            $site->site_name
                        );

                        if (config('site_enrichment.enabled', true)) {
                            CaptureSiteScreenshotJob::dispatch($site->id, 'agency_csv_import');
                        }
                    }

                    $created++;
                } catch (\Exception $e) {
                    Log::error('Bulk site import row failed: '.$e->getMessage(), [
                        'row' => $rowNumber,
                        'user_id' => $publisher->id,
                        'import_id' => $import?->id,
                    ]);
                    $failure = [
                        'row' => $rowNumber,
                        'site' => $data['site_url'] ?? '',
                        'site_name' => $data['site_name'] ?? null,
                        'site_url' => $data['site_url'] ?? null,
                        'errors' => ['Could not save this row. Please check the data.'],
                    ];
                    $failed[] = [
                        'row' => $failure['row'],
                        'site' => $failure['site'],
                        'errors' => $failure['errors'],
                    ];
                    $failureRecords[] = $failure;
                }
            }

            if ($import !== null) {
                foreach ($failureRecords as $failure) {
                    AgencySiteImportFailure::create([
                        'agency_site_import_id' => $import->id,
                        'row_number' => $failure['row'],
                        'site_url' => $failure['site_url'] ?? ($failure['site'] !== '' ? $failure['site'] : null),
                        'site_name' => $failure['site_name'] ?? null,
                        'errors' => $failure['errors'],
                    ]);
                }

                $import->forceFill([
                    'processed_count' => $processed,
                    'created_count' => $created,
                    'failed_count' => count($failed),
                    'would_create_count' => 0,
                ])->save();

                $import->finalizeStatus();

                ActivityLogger::log(
                    'agency_import.submitted',
                    ($publisher->name ?? 'Publisher').' submitted agency CSV import #'.$import->id.': '
                        .$created.' site(s) created, '.count($failed).' row(s) failed',
                    $import,
                    [
                        'import_id' => $import->id,
                        'publisher_id' => $publisher->id,
                        'created_count' => $created,
                        'failed_count' => count($failed),
                        'processed_count' => $processed,
                        'original_filename' => $import->original_filename,
                    ],
                    'Agency import #'.$import->id
                );

                // Admin email notification is handled in PR2 — not sent here.
            }

            return [
                'import' => $import,
                'created' => $created,
                'would_create' => $wouldCreate,
                'failed' => $failed,
                'processed' => $processed,
                'dry_run' => $dryRun,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Normalize + validate one CSV row into site attributes.
     */
    private function normalizeBulkRow(array $data, array $validCategoryNamesLower): array
    {
        $errors = [];

        $siteUrl = $data['site_url'] ?? '';
        if ($siteUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $siteUrl)) {
            $siteUrl = 'https://'.$siteUrl;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $domain = is_string($host) && $host !== ''
            ? Site::normalizeMarketplaceDomain($host)
            : null;
        if (! $domain) {
            $errors[] = 'Invalid site_url.';
        }

        $exampleUrl = $data['example_url'] ?? '';
        if ($exampleUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $exampleUrl)) {
            $exampleUrl = 'https://'.$exampleUrl;
        }

        $categoryRaw = $data['categories'] ?? '';
        $categories = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $categoryRaw) ?: [])));
        if (count($categories) < 1) {
            $errors[] = 'At least one category is required (use | or , between names).';
        } elseif (count($categories) > 7) {
            $errors[] = 'Maximum 7 categories allowed.';
        } else {
            foreach ($categories as $cat) {
                if (! in_array(strtolower($cat), $validCategoryNamesLower, true)) {
                    $errors[] = "Unknown category: {$cat}";
                }
            }
        }

        $countryCodes = array_slice($this->parseCodeList($data['country'] ?? ($data['countries'] ?? '')), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($data['language'] ?? ($data['languages'] ?? '')), 0, 1);
        if (count($countryCodes) < 1) {
            $errors[] = 'A country code is required (e.g. de).';
        }
        if (count($languageCodes) < 1) {
            $errors[] = 'A language code is required (e.g. de).';
        }
        if (
            ($countryCodes[0] ?? null)
            && ($languageCodes[0] ?? null)
            && ! app(CountryLanguagePairs::class)->isAllowedPair($countryCodes[0], $languageCodes[0])
        ) {
            $errors[] = 'Language '.$languageCodes[0].' is not allowed for country '.$countryCodes[0].'.';
        }

        $description = strip_tags((string) ($data['description'] ?? ''), '<p><a><b><strong><i><ul><ol><li><br>');

        $payload = [
            'site_name' => $data['site_name'] ?? '',
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $data['da'] ?? null,
            'dr' => $data['dr'] ?? null,
            'traffic' => $data['traffic'] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
            'price' => $data['price'] ?? null,
            'turnaround_time' => $data['turnaround_time'] ?? '',
            'publication_time' => $data['publication_time'] ?? '',
            'link_type' => strtolower($data['link_type'] ?? ''),
            'description' => $description,
        ];

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        $validator = Validator::make($payload, [
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:255',
            'example_url' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'countries' => 'required|array|size:1',
            'countries.*' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'languages' => 'required|array|size:1',
            'languages.*' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0|max:99999999.99',
            'turnaround_time' => 'required|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'description' => 'required|string|min:50',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $msg) {
                $errors[] = $msg;
            }
        }

        $sensitivePrices = [];
        foreach (['crypto' => 'price_crypto', 'trading' => 'price_trading', 'CBD' => 'price_CBD', 'forex' => 'price_forex'] as $topic => $col) {
            $val = $data[$col] ?? '';
            if ($val !== '' && $val !== null) {
                if (! is_numeric($val) || $val < 0 || (float) $val > 99999999.99) {
                    $errors[] = "{$col} must be a number between 0 and 99999999.99.";
                } else {
                    $sensitivePrices[$topic] = (float) $val;
                }
            }
        }

        if (! empty($errors)) {
            return ['errors' => array_values(array_unique($errors))];
        }

        return [
            'errors' => [],
            'site_name' => $payload['site_name'],
            'site_url' => $payload['site_url'],
            'domain' => $domain,
            'example_url' => $payload['example_url'],
            'da' => (int) $payload['da'],
            'dr' => (int) $payload['dr'],
            'traffic' => (int) $payload['traffic'],
            'country' => $countryCodes[0],
            'countries' => $countryCodes,
            'language' => $languageCodes[0],
            'languages' => $languageCodes,
            'primary_category' => implode(',', $categories),
            'categories' => $categories,
            'price' => $payload['price'],
            'turnaround_time' => $payload['turnaround_time'],
            'publication_time' => $payload['publication_time'],
            'link_type' => $payload['link_type'],
            'sponsored' => $this->csvBool($data['sponsored'] ?? '0'),
            'partner_material' => $this->csvBool($data['partner_material'] ?? '0'),
            'as_you_prefer' => $this->csvBool($data['as_you_prefer'] ?? '0'),
            'description' => $description,
            'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
        ];
    }

    /**
     * Parse country/language codes from array, CSV, or pipe-separated string.
     */
    private function parseCodeList($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[|,]/', (string) $value) ?: [];
        }

        $codes = [];
        foreach ($parts as $part) {
            $code = strtolower(trim((string) $part));
            if ($code !== '' && preg_match('/^[a-z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function csvBool($value): bool
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }
}
