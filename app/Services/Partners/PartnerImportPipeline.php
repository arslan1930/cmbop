<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerConnection;
use App\Models\PartnerImportJob;
use App\Models\PartnerImportRow;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PartnerImportPipeline
{
    public function __construct(
        private PartnerFeedParser $parser,
        private PartnerColumnMapper $mapper,
        private PartnerFx $fx,
        private PartnerReachability $reachability,
        private PartnerPricingService $pricing,
        private PartnerRateChangeService $rateChanges,
        private PartnerCatalogGoLiveService $goLive,
    ) {}

    /**
     * @param  array{actor?: ?User, connection?: ?PartnerConnection, skip_reachability?: bool, confirm_drop?: bool, filename?: string}  $options
     */
    public function importString(Partner $partner, string $source, string $contents, array $options = []): PartnerImportJob
    {
        $job = PartnerImportJob::query()->create([
            'partner_id' => $partner->id,
            'connection_id' => ($options['connection'] ?? null)?->id,
            'source' => $source,
            'status' => PartnerImportJob::STATUS_RUNNING,
            'started_at' => now(),
            'actor_id' => ($options['actor'] ?? null)?->id,
            'counts' => $this->emptyCounts(),
        ]);

        try {
            $path = $this->storeRaw($job, $contents, (string) ($options['filename'] ?? 'feed.csv'));
            $job->raw_payload_path = $path;
            $job->save();

            $rows = $this->parser->parse($contents, (string) ($options['filename'] ?? 'feed.csv'));
            $this->processRows($job, $partner, $rows, $options);
        } catch (\Throwable $e) {
            Log::warning('partner.import.failed', ['job' => $job->id, 'message' => $e->getMessage()]);
            $job->status = PartnerImportJob::STATUS_FAILED;
            $job->error = $e->getMessage();
            $job->finished_at = now();
            $job->save();
        }

        return $job->fresh() ?? $job;
    }

    /**
     * @param  array{actor?: ?User, skip_reachability?: bool, confirm_drop?: bool}  $options
     */
    public function importUpload(Partner $partner, string $filename, string $contents, array $options = []): PartnerImportJob
    {
        $options['filename'] = $filename;

        return $this->importString($partner, 'file_upload', $contents, $options);
    }

    /**
     * Pull-adapter entry: already-mapped CSV-like associative rows.
     *
     * @param  list<array<string, string>>  $rows
     * @param  array{actor?: ?User, connection?: ?PartnerConnection, skip_reachability?: bool, confirm_drop?: bool}  $options
     */
    public function importMappedFieldRows(Partner $partner, string $source, array $rows, array $options = []): PartnerImportJob
    {
        $job = PartnerImportJob::query()->create([
            'partner_id' => $partner->id,
            'connection_id' => ($options['connection'] ?? null)?->id,
            'source' => $source,
            'status' => PartnerImportJob::STATUS_RUNNING,
            'started_at' => now(),
            'actor_id' => ($options['actor'] ?? null)?->id,
            'counts' => $this->emptyCounts(),
        ]);
        try {
            $this->processRows($job, $partner, $rows, $options);
        } catch (\Throwable $e) {
            $job->status = PartnerImportJob::STATUS_FAILED;
            $job->error = $e->getMessage();
            $job->finished_at = now();
            $job->save();
        }

        return $job->fresh() ?? $job;
    }

    public function importFeed(Partner $partner, PartnerConnection $connection, array $options = []): PartnerImportJob
    {
        $options['connection'] = $connection;
        $url = trim((string) $connection->feed_url);
        if ($url === '') {
            $job = PartnerImportJob::query()->create([
                'partner_id' => $partner->id,
                'connection_id' => $connection->id,
                'source' => 'feed_url',
                'status' => PartnerImportJob::STATUS_FAILED,
                'started_at' => now(),
                'finished_at' => now(),
                'error' => 'Feed URL is empty.',
                'counts' => $this->emptyCounts(),
                'actor_id' => ($options['actor'] ?? null)?->id,
            ]);
            $this->markConnection($connection, $job, false);

            return $job;
        }

        $body = $this->fetchFeed($url);
        if ($body === null) {
            $job = PartnerImportJob::query()->create([
                'partner_id' => $partner->id,
                'connection_id' => $connection->id,
                'source' => 'feed_url',
                'status' => PartnerImportJob::STATUS_FAILED,
                'started_at' => now(),
                'finished_at' => now(),
                'error' => 'Feed fetch failed. Last good import was kept.',
                'counts' => $this->emptyCounts(),
                'actor_id' => ($options['actor'] ?? null)?->id,
            ]);
            $this->markConnection($connection, $job, false);

            return $job;
        }

        $filename = $this->filenameFromUrl($url);
        $options['filename'] = $filename;
        $job = $this->importString($partner, 'feed_url', $body, $options);
        $this->markConnection($connection, $job, $job->status !== PartnerImportJob::STATUS_FAILED);

        return $job;
    }

    public function confirmPendingRemovals(PartnerImportJob $job, User $actor): PartnerImportJob
    {
        $ids = array_map('intval', $job->pending_removals ?? []);
        if ($ids !== []) {
            $this->pauseMissing($ids, 'removed_at_source');
        }
        $counts = $job->counts ?? $this->emptyCounts();
        $counts['removed'] = count($ids);
        $job->counts = $counts;
        $job->pending_removals = [];
        $job->status = PartnerImportJob::STATUS_COMPLETED;
        $job->finished_at = now();
        $job->save();
        ActivityLogger::tryLog('partner.import.confirm_drop', 'Confirmed feed drop pauses', $job, [
            'removed' => $ids,
        ], null, $actor);

        return $job;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function processRows(PartnerImportJob $job, Partner $partner, array $rows, array $options): void
    {
        $counts = $this->emptyCounts();
        $seenInFile = [];
        $normalized = [];
        foreach ($rows as $i => $fields) {
            $line = (int) ($fields['_line'] ?? ($i + 1));
            $checked = $this->mapper->normalize($fields, $line);
            $domain = (string) ($checked['row']['domain'] ?? '');
            if ($domain !== '' && isset($seenInFile[$domain])) {
                $this->recordRow($job, $line, $domain, 'rejected', 'duplicate_in_file', 'Duplicate domain in this file.', $fields);
                $counts['rejected']++;

                continue;
            }
            if ($domain !== '') {
                $seenInFile[$domain] = true;
            }
            $normalized[] = ['checked' => $checked, 'fields' => $fields, 'line' => $line];
        }

        $urls = [];
        foreach ($normalized as $entry) {
            $url = (string) ($entry['checked']['row']['site_url'] ?? '');
            if ($url !== '') {
                $urls[] = $url;
            }
            $example = (string) ($entry['checked']['row']['example_url'] ?? '');
            if ($example !== '') {
                $urls[] = $example;
            }
        }
        $reach = $this->reachability->checkMany($urls, (bool) ($options['skip_reachability'] ?? false));

        $presentDomains = [];
        foreach ($normalized as $entry) {
            $result = $this->upsertRow($job, $partner, $entry['checked'], $entry['fields'], $entry['line'], $reach);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
            $domain = (string) ($entry['checked']['row']['domain'] ?? '');
            if ($domain !== '' && in_array($result, ['created', 'updated', 'unchanged', 'blocked'], true)) {
                $presentDomains[] = $domain;
            }
        }

        $missing = $this->missingLiveOrListed($partner, $presentDomains);
        $previous = PartnerCatalogItem::query()->where('partner_id', $partner->id)
            ->whereNotIn('listing_status', [PartnerCatalogItem::STATUS_REMOVED])
            ->count();
        $incoming = count($presentDomains);
        $dropPercent = (int) config('partners.feed_drop_percent', 30);
        $dropHeld = false;
        if ($incoming === 0 && $previous > 0) {
            $dropHeld = true;
        } elseif ($previous > 0 && $incoming < $previous && (($previous - $incoming) / $previous) * 100 > $dropPercent) {
            $dropHeld = true;
        }

        // Plan 2.7 / AC-P2: never pause a missing site (or a vanished/shrunk feed)
        // without admin confirmation. Fetch failures never reach this method.
        if ($missing !== [] && empty($options['confirm_drop'])) {
            $job->pending_removals = $missing;
            $job->status = PartnerImportJob::STATUS_HELD;
            $counts['held_removed'] = count($missing);
            if ($dropHeld) {
                $counts['held_reason'] = $incoming === 0 ? 'feed_disappeared' : 'feed_drop';
            }
        } elseif ($missing !== []) {
            $this->pauseMissing($missing, 'removed_at_source');
            $counts['removed'] = count($missing);
            $job->status = PartnerImportJob::STATUS_COMPLETED;
        } else {
            $job->status = PartnerImportJob::STATUS_COMPLETED;
        }

        $job->counts = $counts;
        $job->finished_at = now();
        $job->save();

        ActivityLogger::tryLog('partner.import.completed', 'Partner catalog import '.$job->status, $job, [
            'counts' => $counts,
            'partner_id' => $partner->id,
        ], null, $options['actor'] ?? null);
    }

    /**
     * @param  array{errors: list<string>, reason_code: ?string, blocked: bool, flags: list<string>, row: array<string, mixed>}  $checked
     * @param  array<string, mixed>  $fields
     * @param  array<string, array{ok: bool, status: ?int}>  $reach
     */
    private function upsertRow(PartnerImportJob $job, Partner $partner, array $checked, array $fields, int $line, array $reach): string
    {
        $row = $checked['row'];
        $domain = (string) ($row['domain'] ?? '');
        if ($checked['reason_code'] === 'unsupported_currency') {
            $this->recordRow($job, $line, $domain, 'rejected', 'unsupported_currency', implode(' ', $checked['errors']), $fields);

            return 'rejected';
        }
        if ($domain === '') {
            $this->recordRow($job, $line, $domain, 'rejected', $checked['reason_code'] ?? 'invalid_url', implode(' ', $checked['errors']) ?: 'Site URL is not a valid http(s) listing URL.', $fields);

            return 'rejected';
        }
        if ($checked['errors'] !== [] && ! $checked['blocked']) {
            $this->recordRow($job, $line, $domain, 'rejected', $checked['reason_code'] ?? 'validation_failed', implode(' ', $checked['errors']), $fields);

            return 'rejected';
        }

        $siteUrl = (string) $row['site_url'];
        $reachable = $reach[$siteUrl]['ok'] ?? true;
        $example = (string) ($row['example_url'] ?? '');
        if ($example !== '' && ! ($reach[$example]['ok'] ?? true)) {
            $reachable = false;
        }

        $fx = $this->fx->toEuros((float) ($row['price'] ?? 0), (string) $row['currency']);
        $writingEur = $row['writing_fee'] !== null ? $this->fx->toEuros((float) $row['writing_fee'], (string) $row['currency']) : null;
        $sensitiveEur = $row['sensitive_extra'] !== null ? $this->fx->toEuros((float) $row['sensitive_extra'], (string) $row['currency']) : null;

        $duplicateSite = $this->findMarketplaceDuplicate($domain, $partner);
        $casino = (bool) $row['casino'];
        $blocked = $casino || $checked['blocked'];

        $existing = PartnerCatalogItem::query()
            ->where('partner_id', $partner->id)
            ->where('domain', $domain)
            ->first();

        $attrs = [
            'partner_id' => $partner->id,
            'last_job_id' => $job->id,
            'domain' => $domain,
            'site_url' => $siteUrl,
            'site_name' => $row['site_name'],
            'description' => $row['description'],
            'contact_email' => $row['email'] ?: null,
            'contact_name' => $row['name'] ?: null,
            'country' => $row['country'],
            'language' => $row['language'],
            'niches' => $row['niches'],
            'raw_niches' => $row['raw_niches'],
            'da' => $row['da'],
            'dr' => $row['dr'],
            'traffic' => $row['traffic'],
            'turnaround_days' => $row['turnaround_days'],
            'turnaround_time' => $row['turnaround_time'],
            'publication_time' => $row['publication_time'],
            'link_type' => $row['link_type'],
            'sponsored_label' => $row['sponsored_label'],
            'example_url' => $row['example_url'],
            'partner_rate_currency' => $row['currency'],
            'fx_rate' => $fx['rate'],
            'fx_rate_date' => $fx['date'],
            'duplicate' => $duplicateSite !== null,
            'duplicate_site_id' => $duplicateSite?->id,
            'unreachable' => ! $reachable,
            'last_reachable_at' => $reachable ? now() : null,
            'casino' => $casino,
            'unmapped_niche' => (bool) $row['unmapped_niche'],
            'niches_truncated' => (bool) $row['niches_truncated'],
            'removed_at_source_at' => null,
        ];

        if ($existing) {
            $rateChanged = $this->rateDiffers($existing, (float) $row['price'], $writingEur['amount'] ?? null, $sensitiveEur['amount'] ?? null, $fx['amount']);
            $wasLive = $existing->isLive();
            $existing->fill($this->descriptiveAttrs($attrs));
            if ($blocked) {
                if ($wasLive) {
                    $this->goLive->pauseLive($existing, 'casino_not_allowed');
                }
                $this->goLive->moveStatus($existing, PartnerCatalogItem::STATUS_BLOCKED, 'system', null, 'casino_not_allowed');
                $existing->block_reason = 'casino_not_allowed';
                $existing->save();
                $this->recordRow($job, $line, $domain, 'blocked', 'casino_not_allowed', 'Casino listings are not accepted.', $fields);

                return 'blocked';
            }
            $ratePayload = [
                'currency' => $row['currency'],
                'publish' => (float) $row['price'],
                'writing' => $row['writing_fee'],
                'sensitive_extra' => $row['sensitive_extra'],
                'publish_eur' => $fx['amount'],
                'writing_eur' => $writingEur['amount'] ?? null,
                'sensitive_extra_eur' => $sensitiveEur['amount'] ?? null,
                'fx_rate' => $fx['rate'],
                'fx_rate_date' => $fx['date'],
            ];
            if ($rateChanged && $wasLive) {
                $this->rateChanges->handle($existing, $ratePayload, $job->source === 'file_upload' ? 'feed' : $job->source);
            } elseif ($rateChanged) {
                $this->rateChanges->applyImmediately($existing, $ratePayload, $job->source === 'file_upload' ? 'feed' : $job->source);
            } else {
                $existing->fill([
                    'partner_rate_publish' => (float) $row['price'],
                    'partner_rate_writing' => $row['writing_fee'],
                    'partner_rate_sensitive_extra' => $row['sensitive_extra'],
                    'partner_rate_publish_eur' => $fx['amount'],
                    'partner_rate_writing_eur' => $writingEur['amount'] ?? null,
                    'partner_rate_sensitive_extra_eur' => $sensitiveEur['amount'] ?? null,
                ]);
            }
            if ($existing->listing_status === PartnerCatalogItem::STATUS_REMOVED) {
                $this->goLive->moveStatus($existing, PartnerCatalogItem::STATUS_UNUSED, 'system', null, 'reappeared');
            }
            if ($wasLive) {
                $this->goLive->syncLiveSite($existing);
            }
            $existing->save();
            $this->recordRow($job, $line, $domain, $rateChanged ? 'updated' : 'unchanged', null, null, $fields);

            return $rateChanged ? 'updated' : 'unchanged';
        }

        $item = new PartnerCatalogItem($attrs);
        $item->listing_status = $blocked ? PartnerCatalogItem::STATUS_BLOCKED : PartnerCatalogItem::STATUS_UNUSED;
        $item->block_reason = $blocked ? 'casino_not_allowed' : null;
        $item->partner_rate_publish = (float) $row['price'];
        $item->partner_rate_writing = $row['writing_fee'];
        $item->partner_rate_sensitive_extra = $row['sensitive_extra'];
        $item->partner_rate_publish_eur = $fx['amount'];
        $item->partner_rate_writing_eur = $writingEur['amount'] ?? null;
        $item->partner_rate_sensitive_extra_eur = $sensitiveEur['amount'] ?? null;
        $item->partner_rate_version = 1;
        $item->partner_rate_effective_from = now();
        $this->pricing->applySuggested($item);
        $item->save();
        $this->goLive->moveStatus($item, $item->listing_status, 'system', null, $blocked ? 'casino_not_allowed' : 'imported');

        if (! $blocked) {
            app(PartnerAutoApproveService::class)->applyToNewItem($item->fresh() ?? $item, $partner);
        }

        $result = $blocked ? 'blocked' : 'created';
        $this->recordRow($job, $line, $domain, $result, $blocked ? 'casino_not_allowed' : null, $blocked ? 'Casino listings are not accepted.' : null, $fields);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function descriptiveAttrs(array $attrs): array
    {
        unset($attrs['partner_id'], $attrs['domain']);

        return $attrs;
    }

    private function rateDiffers(PartnerCatalogItem $item, float $publish, ?float $writingEur, ?float $sensitiveEur, float $publishEur): bool
    {
        if (abs((float) $item->partner_rate_publish_eur - $publishEur) > 0.009) {
            return true;
        }
        if (abs((float) $item->partner_rate_publish - $publish) > 0.009) {
            return true;
        }
        if (round((float) ($item->partner_rate_writing_eur ?? 0), 2) !== round((float) ($writingEur ?? 0), 2)) {
            return true;
        }
        if (round((float) ($item->partner_rate_sensitive_extra_eur ?? 0), 2) !== round((float) ($sensitiveEur ?? 0), 2)) {
            return true;
        }

        return false;
    }

    private function findMarketplaceDuplicate(string $domain, Partner $partner): ?Site
    {
        $occupying = Site::findOccupyingDomain($domain);
        if (! $occupying) {
            return null;
        }
        if (Site::hasSitesColumn('partner_id') && (int) $occupying->partner_id === (int) $partner->id) {
            return null;
        }
        if (Site::hasSitesColumn('partner_catalog_item_id') && $occupying->partner_catalog_item_id) {
            $owned = PartnerCatalogItem::query()->where('id', $occupying->partner_catalog_item_id)->where('partner_id', $partner->id)->exists();
            if ($owned) {
                return null;
            }
        }
        if ((int) $occupying->publisher_id === (int) $partner->user_id) {
            return null;
        }

        return $occupying;
    }

    /**
     * @param  list<string>  $presentDomains
     * @return list<int>
     */
    private function missingLiveOrListed(Partner $partner, array $presentDomains): array
    {
        $query = PartnerCatalogItem::query()
            ->where('partner_id', $partner->id)
            ->whereIn('listing_status', [
                PartnerCatalogItem::STATUS_LIVE,
                PartnerCatalogItem::STATUS_PAUSED,
                PartnerCatalogItem::STATUS_IN_REVIEW,
                PartnerCatalogItem::STATUS_UNUSED,
            ]);
        if ($presentDomains !== []) {
            $query->whereNotIn('domain', $presentDomains);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function pauseMissing(array $ids, string $reason): void
    {
        $items = PartnerCatalogItem::query()->whereIn('id', $ids)->get();
        foreach ($items as $item) {
            $item->removed_at_source_at = now();
            if ($item->isLive()) {
                $this->goLive->pauseLive($item, $reason);
            } else {
                $this->goLive->moveStatus($item, PartnerCatalogItem::STATUS_REMOVED, 'system', null, $reason);
                $item->pause_reason = $reason;
                $item->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function recordRow(PartnerImportJob $job, int $line, string $domain, string $result, ?string $code, ?string $reason, array $raw): void
    {
        PartnerImportRow::query()->create([
            'job_id' => $job->id,
            'row_number' => $line,
            'domain' => $domain !== '' ? $domain : null,
            'result' => $result,
            'reason_code' => $code,
            'reason' => $reason,
            'raw' => $raw,
        ]);
    }

    private function storeRaw(PartnerImportJob $job, string $contents, string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'feed.csv';
        $path = 'partner-imports/'.$job->id.'_'.$safe;
        try {
            Storage::disk('local')->put($path, $contents);
        } catch (\Throwable $e) {
            Log::notice('partner.import.raw_store_failed', ['message' => $e->getMessage()]);
        }

        return $path;
    }

    private function fetchFeed(string $url): ?string
    {
        try {
            $response = Http::timeout(20)->withHeaders(['User-Agent' => 'SEOLinkBuildings-PartnerFeed/1.0'])->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::notice('partner.feed.fetch_failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = is_string($path) ? basename($path) : '';
        if ($base === '' || ! str_contains($base, '.')) {
            return 'feed.csv';
        }

        return $base;
    }

    private function markConnection(PartnerConnection $connection, PartnerImportJob $job, bool $ok): void
    {
        $connection->last_sync_at = now();
        $connection->last_sync_status = $job->status;
        $connection->last_job_id = $job->id;
        if ($ok) {
            $connection->fail_count = 0;
            $connection->status = PartnerConnection::STATUS_ACTIVE;
        } else {
            $connection->fail_count = (int) $connection->fail_count + 1;
            $connection->status = PartnerConnection::STATUS_ERROR;
        }
        $connection->save();
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'rejected' => 0,
            'blocked' => 0,
            'removed' => 0,
        ];
    }
}
