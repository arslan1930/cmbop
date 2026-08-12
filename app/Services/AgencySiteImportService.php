<?php

namespace App\Services;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\AgencySiteImport;
use App\Models\AgencySiteImportFailure;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Support\SiteDescriptionRules;
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
     *   dry_run: bool,
     *   interrupted?: bool
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

            try {
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

                    if (Site::where('domain', $domain)->exists()) {
                        $failure = [
                            'row' => $rowNumber,
                            'site' => $data['site_url'],
                            'site_name' => $data['site_name'] ?? null,
                            'site_url' => $data['site_url'] ?? null,
                            'errors' => ['This domain is already registered in the system.'],
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

                        // Count as created as soon as the row is committed so a later
                        // logging/job failure cannot mark a saved site as a failed row.
                        $created++;

                        if ($site !== null) {
                            try {
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
                            } catch (\Throwable $e) {
                                Log::warning('Agency CSV row activity log failed: '.$e->getMessage(), [
                                    'row' => $rowNumber,
                                    'site_id' => $site->id,
                                ]);
                            }

                            try {
                                if (config('site_enrichment.enabled', true)) {
                                    CaptureSiteScreenshotJob::dispatch($site->id, 'agency_csv_import');
                                }
                            } catch (\Throwable $e) {
                                Log::warning('Agency CSV screenshot dispatch failed: '.$e->getMessage(), [
                                    'row' => $rowNumber,
                                    'site_id' => $site->id,
                                ]);
                            }
                        }
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
                    $this->persistImportOutcome($import, $publisher, $processed, $created, $failed, $failureRecords);
                }

                return [
                    'import' => $import,
                    'created' => $created,
                    'would_create' => $wouldCreate,
                    'failed' => $failed,
                    'processed' => $processed,
                    'dry_run' => $dryRun,
                    'interrupted' => false,
                ];
            } catch (\Throwable $e) {
                // Never leave a batch stuck in "processing". Prefer returning a
                // partial result (so the controller can notify admins) over a 500.
                if ($import !== null && $import->status === AgencySiteImport::STATUS_PROCESSING) {
                    try {
                        $interrupt = [
                            'row' => $rowNumber,
                            'site' => '',
                            'site_name' => null,
                            'site_url' => null,
                            'errors' => [
                                'Import interrupted after '.$created.' site(s) were created; remaining rows were not processed. Please review created sites and re-upload any missing rows.',
                            ],
                        ];
                        $failed[] = [
                            'row' => $interrupt['row'],
                            'site' => $interrupt['site'],
                            'errors' => $interrupt['errors'],
                        ];
                        $failureRecords[] = $interrupt;

                        $this->persistImportOutcome(
                            $import,
                            $publisher,
                            $processed,
                            $created,
                            $failed,
                            $failureRecords,
                            forcePartial: true
                        );

                        if ($created > 0) {
                            Log::error('Agency CSV import interrupted after creating sites: '.$e->getMessage(), [
                                'import_id' => $import->id,
                                'created' => $created,
                            ]);

                            return [
                                'import' => $import->fresh(),
                                'created' => $created,
                                'would_create' => $wouldCreate,
                                'failed' => $failed,
                                'processed' => $processed,
                                'dry_run' => $dryRun,
                                'interrupted' => true,
                            ];
                        }
                    } catch (\Throwable $inner) {
                        Log::warning('Could not finalize agency CSV import after exception: '.$inner->getMessage(), [
                            'import_id' => $import->id,
                        ]);
                    }
                }

                throw $e;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Persist failure rows + counts and finalize import status (once).
     *
     * @param  list<array{row:int,site:string,errors:list<string>}>  $failed
     * @param  list<array{row:int,site:string,site_name:?string,site_url:?string,errors:list<string>}>  $failureRecords
     */
    private function persistImportOutcome(
        AgencySiteImport $import,
        User $publisher,
        int $processed,
        int $created,
        array $failed,
        array $failureRecords,
        bool $forcePartial = false
    ): void {
        // Replace any prior rows so interrupt recovery cannot duplicate failures.
        AgencySiteImportFailure::query()
            ->where('agency_site_import_id', $import->id)
            ->delete();

        foreach ($failureRecords as $failure) {
            AgencySiteImportFailure::create([
                'agency_site_import_id' => $import->id,
                'row_number' => $failure['row'],
                'site_url' => $failure['site_url'] ?? ($failure['site'] !== '' ? $failure['site'] : null),
                'site_name' => $failure['site_name'] ?? null,
                'errors' => $failure['errors'],
            ]);
        }

        $failedCount = count($failed);
        if ($forcePartial) {
            $failedCount = max($failedCount, 1);
        }

        $import->forceFill([
            'processed_count' => $processed,
            'created_count' => $created,
            'failed_count' => $failedCount,
            'would_create_count' => 0,
        ])->save();

        $import->finalizeStatus();

        try {
            ActivityLogger::log(
                'agency_import.submitted',
                ($publisher->name ?? 'Publisher').' submitted agency CSV import #'.$import->id.': '
                    .$created.' site(s) created, '.$failedCount.' row(s) failed',
                $import,
                [
                    'import_id' => $import->id,
                    'publisher_id' => $publisher->id,
                    'created_count' => $created,
                    'failed_count' => $failedCount,
                    'processed_count' => $processed,
                    'original_filename' => $import->original_filename,
                    'interrupted' => $forcePartial,
                ],
                'Agency import #'.$import->id
            );
        } catch (\Throwable $e) {
            Log::warning('Agency CSV import activity log failed: '.$e->getMessage(), [
                'import_id' => $import->id,
            ]);
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
        $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
        if (! $domain) {
            $errors[] = 'Invalid site_url.';
        }

        $exampleUrl = $data['example_url'] ?? '';
        if ($exampleUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $exampleUrl)) {
            $exampleUrl = 'https://'.$exampleUrl;
        }

        $categoryRaw = (string) ($data['categories'] ?? '');
        // Pipe-first + comma-niche aware (e.g. "Marketing, PR & Advertising").
        $categories = Category::parseCatalogCategoryParam($categoryRaw);
        if (count($categories) < 1) {
            $errors[] = 'At least one category is required (separate multiple niches with |).';
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
        foreach (SiteDescriptionRules::errors($description) as $message) {
            $errors[] = $message;
        }

        $payload = [
            'site_name' => $data['site_name'] ?? '',
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $this->normalizeMetricInt($data['da'] ?? null),
            'dr' => $this->normalizeMetricInt($data['dr'] ?? null),
            'traffic' => $this->normalizeMetricInt($data['traffic'] ?? null),
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
            'traffic' => 'required|integer|min:0',
            'countries' => 'required|array|size:1',
            'countries.*' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'languages' => 'required|array|size:1',
            'languages.*' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            // Visible plain-text rules are enforced via SiteDescriptionRules above.
            'description' => 'required|string',
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
                if (! is_numeric($val) || $val < 0) {
                    $errors[] = "{$col} must be a number ≥ 0.";
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
            // Pipe-join keeps comma niches intact (same as single-site create).
            'primary_category' => implode('|', $categories),
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
     * Normalize DA/DR/traffic from CSV cells (commas, decimals, blanks).
     * Parity with Admin\SiteController::normalizeMetricInt.
     */
    private function normalizeMetricInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $raw = trim((string) $value);
        $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $raw)) {
            $raw = str_replace(',', '', $raw);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^\d+,\d+$/', $raw)) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
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
