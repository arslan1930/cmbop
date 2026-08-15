<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use App\Services\CartPricingService;
use App\Services\Catalog\CatalogCountryInventory;
use App\Services\Catalog\CatalogLanguageFilter;
use App\Services\Catalog\SiteUrlVisibility;
use App\Services\SiteDescriptionSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Site extends Model
{
    use ToleratesUnparseableDates;

    protected static function booted(): void
    {
        $bustInventory = static fn () => CatalogCountryInventory::forget();
        static::saved($bustInventory);
        static::deleted($bustInventory);
    }

    protected $fillable = [
        'publisher_id',
        'publisher_accepted_at',
        'assigned_by_user_id',
        'agency_site_import_id',
        'site_name',
        'site_url',
        'site_image', // ADDED - for storing site image path
        'domain', // NEW
        'example_url',
        'da',
        'dr',
        'traffic',
        'metrics_provider',
        'metrics_fetched_at',
        'screenshot_path',
        'screenshot_thumb_path',
        'favicon_path',
        'screenshot_fetched_at',
        'enrichment_status',
        'enrichment_error',
        'metrics_manual',
        'turnaround_time',
        'country',
        'countries',
        'language',
        'languages',
        'category',
        'categories', // NEW - for multiple categories
        'price',
        'publication_time',
        'link_type',
        'sponsored',
        'partner_material',
        'as_you_prefer',
        'description',
        'sensitive_prices',
        'homepage_placement_prices',
        'social_promotion',
        'verified',
        'verified_at',
        'verify_token',
        'verify_token_created_at',
        'verify_method',
        'active',
        'archived_at',
        'owner_id',
        'rating_avg',
        'rating_count',
        'completed_orders_count',
        'featured_until',
        'featured_purchased_at',
        'bulk_discount_enabled',
        'bulk_discount_percent',
        'custom_discount_percent',
        'custom_discount_starts_at',
        'custom_discount_ends_at',
        'custom_discount_notified_at',
        'bulk_site_request_id',
        'onboarding_status',
        'status_reason',
        'status_reason_at',
        'status_reason_by',
    ];

    public const ONBOARDING_AWAITING_DETAILS = 'awaiting_details';

    /** Publisher finished details; waiting for batch Review & submit (not in admin queue). */
    public const ONBOARDING_DETAILS_COMPLETE = 'details_complete';

    public const ONBOARDING_READY_FOR_REVIEW = 'ready_for_review';

    protected $casts = [
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'verify_token_created_at' => 'datetime',
        'publisher_accepted_at' => 'datetime',
        'active' => 'boolean',
        'sponsored' => 'boolean',
        'partner_material' => 'boolean',
        'as_you_prefer' => 'boolean',
        'da' => 'integer',
        'dr' => 'integer',
        'traffic' => 'integer',
        'price' => 'decimal:2',
        'publication_time' => 'string',
        'sensitive_prices' => 'array',
        'homepage_placement_prices' => 'array',
        'social_promotion' => 'array',
        'categories' => 'array',
        'countries' => 'array',
        'languages' => 'array',
        'site_image' => 'string',
        'metrics_manual' => 'boolean',
        'archived_at' => 'datetime',
        'publisher_accepted_at' => 'datetime',
        'metrics_fetched_at' => 'datetime',
        'screenshot_fetched_at' => 'datetime',
        'rating_avg' => 'float',
        'rating_count' => 'integer',
        'completed_orders_count' => 'integer',
        'featured_until' => 'datetime',
        'featured_purchased_at' => 'datetime',
        'bulk_discount_enabled' => 'boolean',
        'bulk_discount_percent' => 'float',
        'custom_discount_percent' => 'float',
        'custom_discount_starts_at' => 'datetime',
        'custom_discount_ends_at' => 'datetime',
        'custom_discount_notified_at' => 'datetime',
        'status_reason_at' => 'datetime',
    ];

    /**
     * Bulk draft still owned by the publisher (filling forms or reviewing before submit).
     */
    public function isPendingPublisherBulkSubmit(): bool
    {
        return $this->awaitsPublisherDetails() || $this->hasDetailsComplete();
    }

    /**
     * Ensure status_reason / status_reason_at / status_reason_by exist so admin
     * unverify/deactivate can persist a reason on older Hostinger DBs.
     */
    public static function ensureStatusReasonColumns(): bool
    {
        static $ensured = false;
        if ($ensured) {
            return Schema::hasColumn('sites', 'status_reason')
                && Schema::hasColumn('sites', 'status_reason_at')
                && Schema::hasColumn('sites', 'status_reason_by');
        }
        $ensured = true;

        try {
            if (! Schema::hasTable('sites')) {
                return false;
            }

            $driver = Schema::getConnection()->getDriverName();
            $needsReason = ! Schema::hasColumn('sites', 'status_reason');
            $needsAt = ! Schema::hasColumn('sites', 'status_reason_at');
            $needsBy = ! Schema::hasColumn('sites', 'status_reason_by');

            if (! $needsReason && ! $needsAt && ! $needsBy) {
                return true;
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                if ($needsReason) {
                    DB::statement('ALTER TABLE `sites` ADD COLUMN `status_reason` TEXT NULL');
                }
                if ($needsAt) {
                    DB::statement('ALTER TABLE `sites` ADD COLUMN `status_reason_at` TIMESTAMP NULL DEFAULT NULL');
                }
                if ($needsBy) {
                    try {
                        DB::statement('ALTER TABLE `sites` ADD COLUMN `status_reason_by` BIGINT UNSIGNED NULL DEFAULT NULL');
                        DB::statement('ALTER TABLE `sites` ADD CONSTRAINT `sites_status_reason_by_foreign` FOREIGN KEY (`status_reason_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
                    } catch (\Throwable $e) {
                        // Column may exist without FK — still usable.
                        Log::warning('sites.status_reason_by added without FK or already present', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                Schema::table('sites', function ($table) use ($needsReason, $needsAt, $needsBy) {
                    if ($needsReason) {
                        $table->text('status_reason')->nullable();
                    }
                    if ($needsAt) {
                        $table->timestamp('status_reason_at')->nullable();
                    }
                    if ($needsBy) {
                        $table->foreignId('status_reason_by')->nullable()->constrained('users')->nullOnDelete();
                    }
                });
            }
        } catch (\Throwable $e) {
            Log::warning('Could not add sites status_reason columns', [
                'error' => $e->getMessage(),
                'hint' => 'Run database/sql/add_sites_status_reason.sql in phpMyAdmin',
            ]);
        }

        return Schema::hasColumn('sites', 'status_reason')
            && Schema::hasColumn('sites', 'status_reason_at')
            && Schema::hasColumn('sites', 'status_reason_by');
    }

    /**
     * Staff edit accepts free-text link types. Older MySQL DBs still have
     * ENUM('dofollow','nofollow') and 500 on values such as "Guest Post".
     */
    public static function ensureLinkTypeColumn(): bool
    {
        static $ensured = false;
        if ($ensured) {
            return true;
        }
        $ensured = true;

        try {
            if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'link_type')) {
                return false;
            }

            $driver = Schema::getConnection()->getDriverName();
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return true;
            }

            $column = collect(DB::select('SHOW COLUMNS FROM `sites` WHERE Field = ?', ['link_type']))->first();
            $type = strtolower((string) ($column->Type ?? ''));
            if ($type === '' || ! str_starts_with($type, 'enum(')) {
                return true;
            }

            DB::statement("ALTER TABLE `sites` MODIFY `link_type` VARCHAR(64) NOT NULL DEFAULT 'dofollow'");
        } catch (\Throwable $e) {
            Log::warning('Could not widen sites.link_type', [
                'error' => $e->getMessage(),
                'hint' => 'Run database/migrations/2026_08_14_120000_widen_sites_link_type_column.php',
            ]);

            return false;
        }

        return true;
    }

    public static function ensureOnboardingStatusColumnAcceptsValues(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            $driver = Schema::getConnection()->getDriverName();
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return;
            }

            if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'onboarding_status')) {
                return;
            }

            $row = DB::selectOne("SHOW COLUMNS FROM `sites` WHERE Field = 'onboarding_status'");
            $type = strtolower((string) ($row->Type ?? ''));

            $needsWiden = str_starts_with($type, 'enum(')
                || (preg_match('/^varchar\((\d+)\)$/', $type, $m) === 1 && (int) $m[1] < 32);

            if (! $needsWiden) {
                return;
            }

            DB::statement('ALTER TABLE `sites` MODIFY `onboarding_status` VARCHAR(32) NULL');
        } catch (\Throwable $e) {
            Log::warning('Could not widen sites.onboarding_status', [
                'error' => $e->getMessage(),
                'hint' => 'Run database/sql/fix_sites_onboarding_status.sql in phpMyAdmin',
            ]);
        }
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeNeedsAdminReview($query)
    {
        $query = $query
            ->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })
            ->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            })
            ->where(function ($q) {
                $q->whereNull('onboarding_status')
                    ->orWhere('onboarding_status', self::ONBOARDING_READY_FOR_REVIEW);
            });

        // Staff-assigned listings wait on publisher accept before the review queue.
        if (static::hasSitesColumn('publisher_accepted_at')) {
            $query->where(function ($q) {
                $q->whereNotNull('publisher_accepted_at')
                    ->orWhereNull('assigned_by_user_id');
            });
        }

        return $query->notFromCancelledBulk();
    }

    public function enrichmentRuns()
    {
        return $this->hasMany(SiteEnrichmentRun::class);
    }

    public function latestEnrichmentRun()
    {
        return $this->hasOne(SiteEnrichmentRun::class)->latestOfMany();
    }

    /**
     * Listings that need a metrics and/or screenshot refresh.
     *
     * Callers add `where('active', 1)` so the admin stale card, stale table,
     * queue-stale, and `sites:enrich --stale` share the same freshness rules.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeStaleForEnrichment(Builder $query): Builder
    {
        $maxAgeDays = max(1, (int) config('site_enrichment.max_age_days', 90));
        $cutoff = now()->subDays($maxAgeDays);
        $hasMetricsAt = static::hasSitesColumn('metrics_fetched_at');
        $hasScreenshot = static::hasSitesColumn('screenshot_path');
        $hasScreenshotAt = static::hasSitesColumn('screenshot_fetched_at');
        $placeholderIds = SiteEnrichmentRun::placeholderScreenshotSiteIds();

        if (! $hasMetricsAt && ! $hasScreenshot && ! $hasScreenshotAt && $placeholderIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $inner) use ($cutoff, $hasMetricsAt, $hasScreenshot, $hasScreenshotAt, $placeholderIds) {
            if ($hasMetricsAt) {
                $inner->whereNull('metrics_fetched_at')
                    ->orWhere('metrics_fetched_at', '<', $cutoff);
            }

            if ($hasScreenshot) {
                $missingScreenshot = function (Builder $q) {
                    $q->whereNull('screenshot_path')->orWhere('screenshot_path', '');
                };
                if ($hasMetricsAt) {
                    $inner->orWhere($missingScreenshot);
                } else {
                    $inner->where($missingScreenshot);
                }
            }

            if ($hasScreenshotAt) {
                if ($hasMetricsAt || $hasScreenshot) {
                    $inner->orWhereNull('screenshot_fetched_at')
                        ->orWhere('screenshot_fetched_at', '<', $cutoff);
                } else {
                    $inner->whereNull('screenshot_fetched_at')
                        ->orWhere('screenshot_fetched_at', '<', $cutoff);
                }
            }

            if ($placeholderIds !== []) {
                $inner->orWhereIn('id', $placeholderIds);
            }
        });
    }

    /**
     * Oldest / missing metrics first — shared by the stale table, Queue stale, and sites:enrich.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeOrderForStaleEnrichment(Builder $query): Builder
    {
        if (static::hasSitesColumn('metrics_fetched_at')) {
            return $query->orderByRaw('metrics_fetched_at IS NULL DESC')
                ->orderBy('metrics_fetched_at')
                ->orderBy('id');
        }

        return $query->orderBy('id');
    }

    /**
     * Human labels for why this listing is in the stale set.
     *
     * @return list<string>
     */
    public function enrichmentStaleReasons(bool $hasPlaceholderScreenshot = false): array
    {
        $reasons = [];
        $maxAgeDays = max(1, (int) config('site_enrichment.max_age_days', 90));
        $cutoff = now()->subDays($maxAgeDays);

        if (static::hasSitesColumn('metrics_fetched_at')) {
            $at = $this->metrics_fetched_at;
            if ($at === null) {
                $reasons[] = 'No metrics';
            } elseif ($at->lt($cutoff)) {
                $reasons[] = 'Metrics stale';
            }
        }

        $hasPathColumn = static::hasSitesColumn('screenshot_path');
        $hasPath = $hasPathColumn && filled($this->screenshot_path);

        if ($hasPathColumn && ! $hasPath) {
            $reasons[] = 'No screenshot';
        }

        if ($hasPlaceholderScreenshot) {
            $reasons[] = 'Placeholder screenshot';
        }

        if (static::hasSitesColumn('screenshot_fetched_at') && $hasPath) {
            $shotAt = $this->screenshot_fetched_at;
            if ($shotAt === null || $shotAt->lt($cutoff)) {
                $reasons[] = 'Screenshot stale';
            }
        }

        return $reasons;
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function ratings()
    {
        return $this->hasMany(SiteRating::class);
    }

    public function approvedRatings()
    {
        return $this->hasMany(SiteRating::class)->approved();
    }

    public function ratingStarsLabel(): string
    {
        $avg = (float) ($this->rating_avg ?? 0);
        $count = (int) ($this->rating_count ?? 0);
        if ($count < 1) {
            return 'No ratings yet';
        }

        return number_format($avg, 1).' / 5 · '.$count.' '.($count === 1 ? 'rating' : 'ratings');
    }

    /**
     * Compact catalog chip: "4.6 (12)" or null when unrated.
     */
    public function catalogRatingCompactLabel(): ?string
    {
        $count = (int) ($this->rating_count ?? 0);
        if ($count < 1) {
            return null;
        }

        return number_format((float) ($this->rating_avg ?? 0), 1).' ('.$count.')';
    }

    public function completedOrdersLabel(): string
    {
        $count = (int) ($this->completed_orders_count ?? 0);
        if ($count < 1) {
            return 'No completed orders yet';
        }

        return $count.' completed '.($count === 1 ? 'order' : 'orders');
    }

    /**
     * Terminal order counts used for completion trust signals.
     *
     * @return array{completed: int, cancelled: int, total: int}
     */
    public function completionOutcomeCounts(): array
    {
        $completed = (int) ($this->completed_orders_count ?? 0);
        $cancelled = (int) ($this->cancelled_orders_count
            ?? OrderItem::query()
                ->where('site_id', $this->id)
                ->whereHas('order', fn ($q) => $q->where('status', 'cancelled'))
                ->count());

        return [
            'completed' => $completed,
            'cancelled' => $cancelled,
            'total' => $completed + $cancelled,
        ];
    }

    /**
     * Completion rate among terminal orders (completed vs cancelled).
     * Returns null when there is no history yet.
     */
    public function completionRatePercent(): ?int
    {
        $counts = $this->completionOutcomeCounts();
        if ($counts['total'] < 1) {
            return null;
        }

        return (int) round(($counts['completed'] / $counts['total']) * 100);
    }

    /**
     * Compact catalog/expand label with absolute counts.
     * Percent is omitted until there are enough terminal orders to avoid small-N mirages.
     */
    public function catalogCompletionCompactLabel(int $minSampleForPercent = 3): ?string
    {
        $counts = $this->completionOutcomeCounts();
        if ($counts['total'] < 1) {
            return null;
        }

        $completed = $counts['completed'];
        $base = $completed.' completed';

        if ($counts['total'] < $minSampleForPercent) {
            return $base;
        }

        $pct = (int) round(($completed / $counts['total']) * 100);

        return $base.' · '.$pct.'%';
    }

    /**
     * Metrics freshness for catalog chrome: fresh | stale | unknown.
     */
    public function metricsFreshnessState(): string
    {
        if (! $this->metrics_fetched_at) {
            return 'unknown';
        }

        return $this->metricsAreFresh() ? 'fresh' : 'stale';
    }

    public static function refreshCompletedOrdersCount(int $siteId): void
    {
        // Hostinger / drifted deploys may lack this denormalized counter.
        // Never throw here: advertiser Approve and auto-approve call this inside
        // the payout transaction, and a missing column used to roll back approval
        // with the generic Swal "Failed to approve order. Please try again."
        if ($siteId < 1 || ! static::hasSitesColumn('completed_orders_count')) {
            return;
        }

        try {
            $count = OrderItem::query()
                ->where('site_id', $siteId)
                ->whereHas('order', function ($q) {
                    $q->where('status', 'completed');
                })
                ->count();

            static::query()->where('id', $siteId)->update([
                'completed_orders_count' => $count,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to refresh sites.completed_orders_count', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isFeatured(): bool
    {
        $until = $this->safeFeaturedUntil();

        return $until !== null && $until->isFuture();
    }

    public function safeFeaturedUntil(): ?\DateTimeInterface
    {
        return $this->safeDateAttribute('featured_until');
    }

    public function safeCustomDiscountEndsAt(): ?\DateTimeInterface
    {
        return $this->safeDateAttribute('custom_discount_ends_at');
    }

    public function safeCustomDiscountStartsAt(): ?\DateTimeInterface
    {
        return $this->safeDateAttribute('custom_discount_starts_at');
    }

    /**
     * Live custom Sale −% only (not bulk packs). Used by catalog On sale filter
     * and new-site digests.
     */
    public function scopeOnDiscount(Builder $query): Builder
    {
        if (! static::hasSitesColumn('custom_discount_percent')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotNull('custom_discount_percent')
            ->where('custom_discount_percent', '>', 0)
            ->whereNotNull('custom_discount_ends_at')
            ->where('custom_discount_ends_at', '>', now())
            ->where(function (Builder $q) {
                $q->whereNull('custom_discount_starts_at')
                    ->orWhere('custom_discount_starts_at', '<=', now());
            });
    }

    public function hasActiveCustomDiscount(): bool
    {
        if (! static::hasSitesColumn('custom_discount_percent') || ! $this->custom_discount_percent) {
            return false;
        }

        $ends = $this->safeCustomDiscountEndsAt();
        if ($ends === null) {
            return false;
        }

        $starts = $this->safeCustomDiscountStartsAt();
        $rawStarts = $this->getAttributes()['custom_discount_starts_at'] ?? null;
        if ($rawStarts !== null && $rawStarts !== '' && $starts === null) {
            return false;
        }

        return ($starts === null || $starts->lte(now())) && $ends->isFuture();
    }

    public function activeCustomDiscountPercent(): ?float
    {
        return $this->hasActiveCustomDiscount()
            ? (float) $this->custom_discount_percent
            : null;
    }

    public function joinsBulkDiscount(): bool
    {
        if (! static::hasSitesColumn('bulk_discount_enabled')) {
            return false;
        }

        return (bool) $this->bulk_discount_enabled
            && $this->bulk_discount_percent !== null
            && (float) $this->bulk_discount_percent > 0;
    }

    public function featurePurchases()
    {
        return $this->hasMany(SiteFeaturePurchase::class);
    }

    /**
     * Human label for the publisher's most recent completed placement.
     * Uses last_completed_at when loaded via withMax, otherwise null.
     */
    public function lastPublicationLabel(): ?string
    {
        $raw = $this->getAttribute('last_completed_at');
        if (! $raw) {
            return null;
        }

        try {
            $at = $raw instanceof Carbon
                ? $raw
                : Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        return 'Last published '.$at->diffForHumans();
    }

    /**
     * How long a guest post stays live (catalog / My Sites display).
     */
    public function publicationDurationLabel(?string $fallback = null): ?string
    {
        $raw = trim((string) ($this->publication_time ?? ''));
        if ($raw === '') {
            return $fallback;
        }

        return match (strtolower($raw)) {
            '6months', '6 months' => '6 months',
            '1year', '1 year' => '1 year',
            'permanent' => 'Permanent',
            default => preg_match('/^(\d+)\s*days?$/i', $raw, $m)
                ? ((int) $m[1] === 1 ? '1 day' : ((int) $m[1]).' days')
                : $raw,
        };
    }

    /**
     * Typical publisher turnaround once an order is accepted.
     */
    public function turnaroundLabel(?string $fallback = null): ?string
    {
        $raw = trim((string) ($this->turnaround_time ?? ''));
        if ($raw === '') {
            return $fallback;
        }

        return match (strtolower($raw)) {
            '24h' => '24 hours',
            '48h' => '48 hours',
            '3days', '3 days' => '3 days',
            '5days', '5 days' => '5 days',
            '7days', '7 days' => '7 days',
            default => $raw,
        };
    }

    /**
     * The turnaround the publisher promised, in hours.
     *
     * turnaround_time is a short enum on the listing forms (24h, 48h, 3days,
     * 5days, 7days) but older rows hold free text like "7 days", so parse
     * rather than map. Returns null when it cannot be read, which callers treat
     * as "no deadline to hold them to".
     */
    public function turnaroundHours(): ?int
    {
        $raw = strtolower(trim((string) ($this->turnaround_time ?? '')));

        if ($raw === '') {
            return null;
        }

        if (! preg_match('/(\d+)\s*(h|hour|hours|d|day|days|w|week|weeks)?/', $raw, $m)) {
            return null;
        }

        $value = max(1, (int) $m[1]);
        $unit = $m[2] ?? 'd';

        return match (true) {
            str_starts_with($unit, 'h') => $value,
            str_starts_with($unit, 'w') => $value * 24 * 7,
            default => $value * 24,
        };
    }

    /**
     * Link attribute label for chips / tags (DoFollow / NoFollow).
     */
    public function linkTypeLabel(?string $fallback = null): ?string
    {
        $raw = strtolower(trim((string) ($this->link_type ?? '')));
        if ($raw === '') {
            return $fallback;
        }

        return match ($raw) {
            'dofollow' => 'DoFollow',
            'nofollow' => 'NoFollow',
            default => ucfirst($raw),
        };
    }

    /**
     * Get the publisher that owns the site.
     */
    public function publisher()
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    public function agencySiteImport()
    {
        return $this->belongsTo(AgencySiteImport::class, 'agency_site_import_id');
    }

    public function bulkSiteRequest()
    {
        return $this->belongsTo(BulkSiteRequest::class);
    }

    /**
     * Strip www, trailing dots, ports, and case so example.com:443 matches example.com.
     */
    public static function normalizeMarketplaceDomain(string $host): string
    {
        $domain = strtolower(trim($host));
        $domain = preg_replace('/^www\./i', '', $domain) ?? $domain;
        $domain = rtrim($domain, '.');
        if ($domain !== '' && ! str_starts_with($domain, '[') && str_contains($domain, ':')) {
            $domain = explode(':', $domain, 2)[0];
        }

        $domain = rtrim($domain, '.');
        if ($domain !== '' && function_exists('idn_to_ascii') && ! filter_var($domain, FILTER_VALIDATE_IP) && ! str_starts_with($domain, '[')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $domain = strtolower($ascii);
            }
        }

        return $domain;
    }

    /**
     * @return list<string>
     */
    public static function domainLookupCandidates(string $host): array
    {
        $normalized = static::normalizeMarketplaceDomain($host);
        if ($normalized === '') {
            return [];
        }

        $candidates = [
            $normalized,
            'www.'.$normalized,
            $normalized.'.',
            'www.'.$normalized.'.',
            $normalized.':80',
            $normalized.':443',
            'www.'.$normalized.':80',
            'www.'.$normalized.':443',
        ];
        if (function_exists('idn_to_utf8')) {
            $utf8 = idn_to_utf8($normalized, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($utf8) && $utf8 !== '' && strtolower($utf8) !== $normalized) {
                $utf8 = strtolower($utf8);
                $candidates[] = $utf8;
                $candidates[] = 'www.'.$utf8;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Prefer a live listing when legacy duplicates exist so the restore copy
     * is not shown while a non-archived row already occupies the domain.
     * Cancelled-bulk leftovers can never return to the catalog, so they do
     * not occupy the marketplace domain.
     */
    public static function findOccupyingDomain(string $domain, ?int $exceptId = null, bool $lock = false): ?self
    {
        $candidates = static::domainLookupCandidates($domain);
        if ($candidates === []) {
            return null;
        }

        $normalized = static::normalizeMarketplaceDomain($domain);
        $query = static::query()->where(function ($q) use ($candidates, $normalized) {
            $q->whereIn('domain', $candidates);
            if ($normalized !== '') {
                $escaped = addcslashes($normalized, '%_\\');
                $q->orWhere('domain', 'like', $escaped.':%')
                    ->orWhere('domain', 'like', 'www.'.$escaped.':%');
            }
        });
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if (static::hasSitesColumn('bulk_site_request_id')) {
            $query->notFromCancelledBulk();
        }
        if (static::hasSitesColumn('archived_at')) {
            $query->orderByRaw('case when archived_at is null then 0 else 1 end');
        }
        $query->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Same publisher still has unique(publisher_id, domain) on leftovers.
     * Delete unused cancelled rows; tombstone leftovers that keep order history.
     */
    public static function releaseCancelledBulkDomain(string $domain, int $publisherId, bool $lock = false): int
    {
        if ($publisherId <= 0 || ! static::hasSitesColumn('bulk_site_request_id')) {
            return 0;
        }

        $candidates = static::domainLookupCandidates($domain);
        if ($candidates === []) {
            return 0;
        }

        $normalized = static::normalizeMarketplaceDomain($domain);
        $query = static::query()
            ->where('publisher_id', $publisherId)
            ->where(function ($q) use ($candidates, $normalized) {
                $q->whereIn('domain', $candidates);
                if ($normalized !== '') {
                    $escaped = addcslashes($normalized, '%_\\');
                    $q->orWhere('domain', 'like', $escaped.':%')
                        ->orWhere('domain', 'like', 'www.'.$escaped.':%');
                }
            })
            ->whereHas('bulkSiteRequest', function ($bulk) {
                $bulk->where('status', BulkSiteRequest::STATUS_CANCELLED);
            })
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $leftovers = $query->get();

        $released = 0;
        foreach ($leftovers as $site) {
            if ($site->releaseCancelledBulkOccupancy()) {
                $released++;
            }
        }

        return $released;
    }

    public function releaseCancelledBulkOccupancy(): bool
    {
        if (! $this->isFromCancelledBulk()) {
            return false;
        }

        if ($this->orderItemsCount() === 0) {
            $this->delete();

            return true;
        }

        $tombstone = 'cancelled-'.$this->id.'.invalid';
        if ($this->domain === $tombstone) {
            return false;
        }

        $this->domain = $tombstone;
        $this->save();

        return true;
    }

    public function occupyingDomainMessage(): string
    {
        return $this->isArchived()
            ? 'This domain is already registered (including archived). Ask an admin to restore or hard-delete.'
            : 'This website domain is already registered.';
    }

    /**
     * Hide leftover drafts from a cancelled bulk (older cancels did not delete them).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotFromCancelledBulk(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('bulk_site_request_id')
                ->orWhereHas('bulkSiteRequest', function ($bulk) {
                    $bulk->where('status', '!=', BulkSiteRequest::STATUS_CANCELLED);
                });
        });
    }

    public function isFromAgencyCsvImport(): bool
    {
        if (! static::hasSitesColumn('agency_site_import_id')) {
            return false;
        }

        return (int) ($this->agency_site_import_id ?? 0) > 0;
    }

    public function awaitsPublisherDetails(): bool
    {
        if (! static::hasSitesColumn('onboarding_status')) {
            return false;
        }

        return $this->onboarding_status === self::ONBOARDING_AWAITING_DETAILS;
    }

    public function hasDetailsComplete(): bool
    {
        if (! static::hasSitesColumn('onboarding_status')) {
            return false;
        }

        return $this->onboarding_status === self::ONBOARDING_DETAILS_COMPLETE;
    }

    /**
     * Publisher finished listing details; waiting for Review & submit (not admin queue yet).
     *
     * @return bool false when the DB cannot store details_complete (caller should flash an error)
     */
    public function markDetailsComplete(): bool
    {
        if (! static::hasSitesColumn('onboarding_status')) {
            return false;
        }

        self::ensureOnboardingStatusColumnAcceptsValues();

        $previous = $this->onboarding_status;
        $this->onboarding_status = self::ONBOARDING_DETAILS_COMPLETE;

        try {
            $this->save();

            return true;
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'onboarding_status')) {
                throw $e;
            }

            Log::warning('Could not set onboarding_status=details_complete', [
                'site_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            $this->onboarding_status = $previous;

            return false;
        }
    }

    public function markReadyForAdminReview(): bool
    {
        if (! static::hasSitesColumn('onboarding_status')) {
            return false;
        }

        self::ensureOnboardingStatusColumnAcceptsValues();

        $this->onboarding_status = self::ONBOARDING_READY_FOR_REVIEW;

        try {
            $this->save();

            return true;
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'onboarding_status')) {
                throw $e;
            }

            Log::warning('Could not set onboarding_status=ready_for_review; falling back to null', [
                'site_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            $this->onboarding_status = null;
            $this->save();

            return true;
        }
    }

    /**
     * Marketing may delete pending / not-live sites only (never verified or active portal listings).
     */
    public function canBeDeletedByMarketing(): bool
    {
        return ! (bool) $this->verified && ! (bool) $this->active;
    }

    /**
     * Live, verified, or archived listings are read-only for marketing.
     */
    public function isLockedForMarketingEdits(): bool
    {
        return (bool) $this->verified || (bool) $this->active || $this->isArchived();
    }

    /**
     * Pending listings marketing may change (not live, verified, or archived).
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeEditableByMarketing(Builder $query): Builder
    {
        return $query->where('verified', 0)->where('active', 0)->notArchived();
    }

    /**
     * Hard delete is safe only for pending listings that were never ordered.
     */
    public function canBeHardDeleted(): bool
    {
        return ! (bool) $this->verified
            && ! (bool) $this->active
            && ! $this->isArchived()
            && $this->orderItemsCount() === 0;
    }

    public function orderItemsCount(): int
    {
        if (array_key_exists('order_items_count', $this->getAttributes())) {
            return (int) $this->getAttribute('order_items_count');
        }

        if (! Schema::hasTable('order_items')) {
            return 0;
        }

        return (int) $this->orderItems()->count();
    }

    /**
     * Staff hide of a live listing: keep the row (and order history), drop it from the catalog.
     */
    public function archiveByStaff(?string $reason = null): bool
    {
        if (! static::hasSitesColumn('archived_at')) {
            return false;
        }

        $this->archived_at = now();
        $this->active = 0;

        if ($reason !== null && $reason !== '') {
            static::ensureStatusReasonColumns();
            $this->status_reason = $reason;
            $this->status_reason_at = now();
            $this->status_reason_by = auth()->id();
        }

        $this->save();

        return true;
    }

    /**
     * Promote stale bulk drafts to ready_for_review when details are already filled.
     */
    public function promoteFromAwaitingDetailsIfComplete(): bool
    {
        if (! $this->awaitsPublisherDetails()) {
            return false;
        }

        if (! $this->hasCompletedPublisherDetails()) {
            return false;
        }

        return $this->clearAwaitingDetailsOnboarding();
    }

    /**
     * Admin explicit approve/activate: drop the awaiting_details lock.
     */
    public function clearAwaitingDetailsForAdmin(): bool
    {
        if (! $this->awaitsPublisherDetails()) {
            return false;
        }

        return $this->clearAwaitingDetailsOnboarding();
    }

    private function clearAwaitingDetailsOnboarding(): bool
    {
        if (! static::hasSitesColumn('onboarding_status')) {
            return false;
        }

        $ok = $this->markReadyForAdminReview();

        if ($ok && $this->bulk_site_request_id) {
            $this->bulkSiteRequest?->refreshProgressStatus();
        }

        return $ok;
    }

    /**
     * Whether required listing details look complete (used to heal stale awaiting_details).
     * example_url is optional — publishers often leave it blank.
     */
    public function hasCompletedPublisherDetails(): bool
    {
        $description = trim((string) ($this->description ?? ''));
        $niches = collect($this->categories_array ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '' && strtolower($v) !== 'pending')
            ->values()
            ->all();

        if (strlen($description) < 50) {
            return false;
        }

        if (str_starts_with($description, 'Please replace')) {
            return false;
        }

        if ($niches === []) {
            return false;
        }

        if (trim((string) ($this->turnaround_time ?? '')) === '') {
            return false;
        }

        if (trim((string) ($this->publication_time ?? '')) === '') {
            return false;
        }

        if (trim((string) ($this->link_type ?? '')) === '') {
            return false;
        }

        return true;
    }

    public function isReadyForAdminReview(): bool
    {
        if (! static::hasSitesColumn('onboarding_status')) {
            return true;
        }

        // details_complete = publisher preview stage; not admin-queueable yet.
        return $this->onboarding_status === null
            || $this->onboarding_status === self::ONBOARDING_READY_FOR_REVIEW;
    }

    /**
     * Open admin review queue: not verified, not live, details ready
     * (excludes awaiting_details and details_complete publisher drafts).
     * Cleared from the queue when admin verifies and/or activates (or deletes).
     */
    public function needsAdminReview(): bool
    {
        return ! (bool) $this->verified
            && ! (bool) $this->active
            && $this->isReadyForAdminReview()
            && $this->isAcceptedByPublisher()
            && ! $this->isFromCancelledBulk();
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeAcceptedByPublisher($query)
    {
        if (! static::hasSitesColumn('publisher_accepted_at')
            || ! static::hasSitesColumn('assigned_by_user_id')) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNotNull('publisher_accepted_at')
                ->orWhereNull('assigned_by_user_id');
        });
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopePendingPublisherAcceptance($query)
    {
        if (! static::hasSitesColumn('publisher_accepted_at')
            || ! static::hasSitesColumn('assigned_by_user_id')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNull('publisher_accepted_at')
            ->whereNotNull('assigned_by_user_id');
    }

    /**
     * Scope a query to only include active sites.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    /**
     * Scope a query to only include verified sites.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified', 1);
    }

    /**
     * Exclude soft-archived sites from marketplace queries.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        if (! static::hasSitesColumn('archived_at')) {
            return $query;
        }

        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        if (! static::hasSitesColumn('archived_at')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        if (! static::hasSitesColumn('archived_at')) {
            return false;
        }

        return $this->archived_at !== null;
    }

    /**
     * Advertiser catalog / cart inventory: live, approved, and not staff-archived.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeCatalogVisible(Builder $query): Builder
    {
        $query->active()->verified()->notArchived();
        if (static::hasSitesColumn('bulk_site_request_id')) {
            $query->notFromCancelledBulk();
        }

        return $query;
    }

    public function isCatalogVisible(): bool
    {
        return (bool) $this->active
            && (bool) $this->verified
            && ! $this->isArchived()
            && ! $this->isFromCancelledBulk();
    }

    public function isFromCancelledBulk(): bool
    {
        if (! $this->bulk_site_request_id) {
            return false;
        }

        $bulk = $this->relationLoaded('bulkSiteRequest')
            ? $this->bulkSiteRequest
            : $this->bulkSiteRequest()->first();

        return (bool) $bulk?->isCancelled();
    }

    public function canBeActivated(): bool
    {
        return $this->activationBlockReason() === null;
    }

    public function activationBlockReason(bool $requireVerified = true): ?string
    {
        if ($this->isArchived()) {
            return 'This site is archived and cannot be activated.';
        }

        if ($this->isFromCancelledBulk()) {
            return 'This listing is from a cancelled bulk request and cannot be activated.';
        }

        if ($this->awaitsPublisherDetails()) {
            return 'Publisher details are still incomplete. The listing cannot be activated yet.';
        }

        if ($this->isPendingPublisherAcceptance()) {
            return 'This site is waiting for the publisher to accept it into My Sites.';
        }

        if ($requireVerified && ! (bool) $this->verified) {
            return 'Verify this site before activating it.';
        }

        if (! $this->hasMarketplaceCountry()) {
            return 'Set a marketplace country before activating this site.';
        }

        return null;
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * Staff-assigned listing waiting for publisher Accept/Decline.
     * Requires publisher_accepted_at IS NULL and assigned_by_user_id set
     * (plain publisher drafts are not invites).
     */
    public function isPendingPublisherAcceptance(): bool
    {
        if (! static::hasSitesColumn('publisher_accepted_at')) {
            return false;
        }

        return $this->publisher_accepted_at === null
            && filled($this->assigned_by_user_id);
    }

    public function isAcceptedByPublisher(): bool
    {
        if (! static::hasSitesColumn('publisher_accepted_at')) {
            return true;
        }

        // Legacy / self-created rows are accepted; only staff-assigned nulls wait.
        if ($this->publisher_accepted_at !== null) {
            return true;
        }

        return blank($this->assigned_by_user_id);
    }

    public function claims()
    {
        return $this->hasMany(SiteClaim::class);
    }

    /**
     * Scope a query to filter sites based on various criteria.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('site_url', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('site_name', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%")
                        ->orWhere('categories', 'like', "%{$search}%"); // NEW - search in categories JSON
                });
            })
            ->when(isset($filters['verified']) && $filters['verified'] == 1, function ($query) {
                $query->where('verified', 1);
            })
            ->when($filters['da_min'] ?? null, function ($query, $min) {
                $query->where('da', '>=', (int) $min);
            })
            ->when($filters['da_max'] ?? null, function ($query, $max) {
                $query->where('da', '<=', (int) $max);
            })
            ->when($filters['dr_min'] ?? null, function ($query, $min) {
                $query->where('dr', '>=', (int) $min);
            })
            ->when($filters['dr_max'] ?? null, function ($query, $max) {
                $query->where('dr', '<=', (int) $max);
            })
            ->when($filters['traffic_min'] ?? null, function ($query, $min) {
                $query->where('traffic', '>=', (int) $min);
            })
            ->when($filters['traffic_max'] ?? null, function ($query, $max) {
                $query->where('traffic', '<=', (int) $max);
            })
            ->when($filters['price_min'] ?? null, function ($query, $min) {
                $query->where('price', '>=', (float) $min);
            })
            ->when($filters['price_max'] ?? null, function ($query, $max) {
                $query->where('price', '<=', (float) $max);
            })
            ->when($filters['country'] ?? null, function ($query, $country) {
                $codes = is_array($country) ? $country : [$country];
                // Primary country only — same rule as advertiser catalog filters.
                app(CatalogCountryInventory::class)
                    ->constrainQueryToPrimaryCountries($query, $codes);
            })
            ->when($filters['language'] ?? null, function ($query, $language) {
                $codes = is_array($language) ? $language : [$language];
                // Option A: all sites offering these languages (same as catalog listing).
                app(CatalogLanguageFilter::class)->constrainQuery($query, $codes);
            })
            ->when($filters['category'] ?? null, function ($query, $category) {
                $query->where(function ($q) use ($category) {
                    $q->where('category', $category)
                        ->orWhereJsonContains('categories', $category); // NEW - search in categories JSON array
                });
            })
            ->when($filters['link_type'] ?? null, function ($query, $linkType) {
                $query->where('link_type', $linkType);
            })
            ->when(isset($filters['sponsored']) && in_array($filters['sponsored'], [0, 1]), function ($query) use ($filters) {
                $query->where('sponsored', $filters['sponsored']);
            });
    }

    /**
     * Scope for sorting sites.
     */
    public function scopeSortBy(Builder $query, ?string $field, ?string $direction = 'desc'): Builder
    {
        $allowedSorts = ['da', 'dr', 'traffic', 'price', 'created_at', 'site_name'];
        $field = in_array($field, $allowedSorts) ? $field : 'created_at';
        $direction = in_array(strtolower($direction), ['asc', 'desc']) ? $direction : 'desc';

        return $query->orderBy($field, $direction);
    }

    /** Listing quality bar used by hasGoodMetrics / withGoodMetrics. */
    public const GOOD_MIN_DA = 30;

    public const GOOD_MIN_DR = 30;

    public const GOOD_MIN_TRAFFIC = 10000;

    /**
     * Get sites with minimum metrics.
     */
    public function scopeWithMinMetrics(Builder $query, int $minDa = 0, int $minDr = 0, int $minTraffic = 0): Builder
    {
        return $query->where('da', '>=', $minDa)
            ->where('dr', '>=', $minDr)
            ->where('traffic', '>=', $minTraffic);
    }

    /**
     * Marketplace quality gate: DA≥30, DR≥30, traffic≥10k.
     */
    public function scopeWithGoodMetrics(Builder $query): Builder
    {
        return $query->withMinMetrics(self::GOOD_MIN_DA, self::GOOD_MIN_DR, self::GOOD_MIN_TRAFFIC);
    }

    /**
     * Accessor for formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return '$'.number_format($this->price, 2);
    }

    /**
     * Root-relative URL for a public-disk path.
     * Prefer /media (Laravel disk stream) so Hostinger broken public/storage
     * symlinks do not blank My Sites / catalog previews.
     */
    public static function publicDiskUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $trimmed = trim($path);
        // Already an absolute / protocol-relative URL — leave it alone.
        if (preg_match('#^(https?:)?//#i', $trimmed) === 1) {
            return $trimmed;
        }

        $normalized = ltrim(str_replace('\\', '/', $trimmed), '/');
        // Strip accidental storage/ or media/ prefixes (avoid /media/media/...).
        foreach (['storage/', 'media/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = ltrim(substr($normalized, strlen($prefix)), '/');
            }
        }
        if ($normalized === '') {
            return null;
        }

        return '/media/'.$normalized;
    }

    /**
     * /media then /storage for client onerror recovery.
     *
     * @return list<string>
     */
    public static function publicDiskUrlFallbacks(?string $path): array
    {
        $primary = static::publicDiskUrl($path);
        if ($primary === null) {
            return [];
        }

        // Absolute URLs have no /storage twin.
        if (preg_match('#^(https?:)?//#i', $primary) === 1) {
            return [$primary];
        }

        $normalized = ltrim(substr($primary, strlen('/media/')), '/');

        return array_values(array_unique([
            '/media/'.$normalized,
            '/storage/'.$normalized,
        ]));
    }

    /**
     * Failed screenshot captures store gray *-placeholder.webp files that still
     * HTTP 200 — prefer real uploads/captures over those when building chains.
     */
    public static function isPlaceholderPreviewPath(?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        return str_contains(strtolower($path), '-placeholder');
    }

    /**
     * Build /media+/storage URL chain from ordered disk paths.
     * Skips placeholder captures when any non-placeholder candidate exists.
     *
     * @param  list<mixed>  $candidates
     * @return list<string>
     */
    public function previewUrlChainFrom(array $candidates): array
    {
        $usable = [];
        $placeholders = [];
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            if (static::isPlaceholderPreviewPath($candidate)) {
                $placeholders[] = $candidate;
            } else {
                $usable[] = $candidate;
            }
        }

        $ordered = $usable !== [] ? $usable : $placeholders;
        $urls = [];
        foreach ($ordered as $path) {
            foreach (static::publicDiskUrlFallbacks($path) as $url) {
                if (! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Catalog Site Details homepage preview: full → thumb → cover.
     *
     * @return list<string>
     */
    public function homepagePreviewUrlChain(): array
    {
        return $this->previewUrlChainFrom([
            $this->screenshot_path,
            $this->screenshot_thumb_path,
            $this->site_image,
        ]);
    }

    /**
     * Publisher My Sites / staff list thumbs: uploaded cover first (matches admin),
     * then screenshot thumb/full. Auto-screenshots and gray placeholders must not
     * hide a real admin/marketing cover upload.
     *
     * @return list<string>
     */
    public function listingPreviewUrlChain(): array
    {
        return $this->previewUrlChainFrom([
            $this->site_image,
            $this->screenshot_thumb_path,
            $this->screenshot_path,
        ]);
    }

    /**
     * Hover/detail zoom: full capture → cover → thumb.
     *
     * @return list<string>
     */
    public function zoomPreviewUrlChain(): array
    {
        return $this->previewUrlChainFrom([
            $this->screenshot_path,
            $this->site_image,
            $this->screenshot_thumb_path,
        ]);
    }

    /**
     * Accessor for full image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        return static::publicDiskUrl(
            is_string($this->site_image) ? $this->site_image : null
        );
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        $path = $this->screenshot_path ?: $this->site_image;
        if (! is_string($path) || $path === '') {
            return null;
        }

        return static::publicDiskUrl($path);
    }

    public function getScreenshotThumbUrlAttribute(): ?string
    {
        $path = $this->screenshot_thumb_path ?: $this->screenshot_path ?: $this->site_image;
        if (! is_string($path) || $path === '') {
            return null;
        }

        return static::publicDiskUrl($path);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->favicon_path) {
            return static::publicDiskUrl($this->favicon_path);
        }

        return $this->image_url;
    }

    /**
     * Most recent enrichment timestamp for "Last updated" (metrics preferred).
     * Does not fall back to updated_at — listing edits must not fake metric freshness.
     */
    public function getMetricsUpdatedAtAttribute(): ?Carbon
    {
        $candidates = array_filter([
            $this->metrics_fetched_at,
            $this->screenshot_fetched_at,
        ]);

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->max();
    }

    public function metricsAreFresh(): bool
    {
        $at = $this->metrics_fetched_at;
        if (! $at) {
            return false;
        }

        $maxDays = (int) config('site_enrichment.max_age_days', 90);

        return $at->gte(now()->subDays($maxDays));
    }

    /**
     * Human label like "2 days ago". Blank when older than max age (do not show stale trust signals).
     */
    public function lastUpdatedLabel(): ?string
    {
        $at = $this->metrics_updated_at;
        if (! $at) {
            return null;
        }

        $maxDays = (int) config('site_enrichment.max_age_days', 90);
        if ($at->lt(now()->subDays($maxDays))) {
            return null;
        }

        return $at->diffForHumans();
    }

    public function formattedTraffic(): string
    {
        if ($this->traffic === null) {
            return '—';
        }

        $n = (int) $this->traffic;
        if ($n >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.').'M';
        }
        if ($n >= 1000) {
            return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.').'K';
        }

        return number_format($n);
    }

    public function averagePublishLabel(): string
    {
        $raw = $this->publication_time ?: $this->turnaround_time;
        if (! filled($raw)) {
            return '—';
        }

        if (is_numeric($raw)) {
            $days = (int) $raw;

            return $days === 1 ? '1 Day' : $days.' Days';
        }

        return (string) $raw;
    }

    public function primaryCountryCode(): ?string
    {
        // Scalar sites.country wins (same as catalog inventory / country filter).
        return app(CatalogCountryInventory::class)
            ->primaryCountryCode($this->country, $this->countries);
    }

    public function primaryLanguageCode(): ?string
    {
        $codes = $this->languageCodes();

        return $codes[0] ?? null;
    }

    /**
     * Check if site clears the marketplace quality bar (DA/DR/traffic).
     */
    public function hasGoodMetrics(): bool
    {
        return (int) $this->da >= self::GOOD_MIN_DA
            && (int) $this->dr >= self::GOOD_MIN_DR
            && (int) $this->traffic >= self::GOOD_MIN_TRAFFIC;
    }

    /**
     * Marketing may activate this listing (pending, complete, market + quality bar).
     */
    public function marketingCanActivate(): bool
    {
        if ((bool) $this->active || $this->isArchived() || $this->isFromCancelledBulk()) {
            return false;
        }
        if ($this->isPendingPublisherAcceptance() || $this->isPendingPublisherBulkSubmit()) {
            return false;
        }

        return $this->hasMarketplaceCountry() && $this->hasGoodMetrics();
    }

    /**
     * Scope to get sites by publisher.
     */
    public function scopeForPublisher(Builder $query, int $publisherId): Builder
    {
        return $query->where('publisher_id', $publisherId);
    }

    /**
     * Assign listing attributes only when the DB column exists.
     * Fits legacy `category` VARCHAR(50) by storing a short primary value
     * while keeping the full list in `categories` when available.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyMarketplaceListing(array $attributes): void
    {
        $categories = $attributes['categories'] ?? null;
        if (array_key_exists('category', $attributes)) {
            $attributes['category'] = static::fitCategoryColumn(
                (string) $attributes['category'],
                is_array($categories) ? $categories : null
            );
        }

        foreach ($attributes as $column => $value) {
            if (! static::hasSitesColumn($column)) {
                continue;
            }
            if ($column === 'description' && is_string($value)) {
                $value = app(SiteDescriptionSanitizer::class)->sanitize($value);
            }
            $this->{$column} = $value;
        }
    }

    /**
     * HTML-safe description for Blade {!! !!} rendering (also cleans legacy rows).
     */
    public function safeDescriptionHtml(): string
    {
        return app(SiteDescriptionSanitizer::class)
            ->sanitize((string) ($this->description ?? ''));
    }

    /**
     * Catalog Details HTML: sanitised description with listing-host anchors
     * rewritten through /advertiser/go/{id}?path= so “Copy link address”
     * cannot lift the publisher URL.
     */
    public function catalogDescriptionHtml(): string
    {
        $html = $this->safeDescriptionHtml();
        if ($html === '' || ! str_contains($html, '<a ')) {
            return $html;
        }

        $visibility = app(SiteUrlVisibility::class);
        $allowed = array_values(array_unique(array_filter([
            strtolower($visibility->host($this->site_url)),
            strtolower($visibility->host((string) $this->example_url)),
        ])));
        if ($allowed === []) {
            return $html;
        }

        $visit = route('advertiser.catalog.visit', $this->id);

        return preg_replace_callback(
            '/<a\s+href="([^"]*)"/i',
            function (array $m) use ($visibility, $allowed, $visit) {
                $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $host = strtolower($visibility->host($href));
                if ($host === '' || ! in_array($host, $allowed, true)) {
                    return $m[0];
                }

                $path = (string) (parse_url($href, PHP_URL_PATH) ?: '/');
                if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
                    return '<a href="'.e($visit).'"';
                }

                $query = parse_url($href, PHP_URL_QUERY);
                $rel = $path.($query ? '?'.$query : '');
                if (strlen($rel) > 500 || str_contains($rel, '\\') || str_contains($rel, '://')) {
                    return '<a href="'.e($visit).'"';
                }

                return '<a href="'.e($visit.'?path='.rawurlencode($rel)).'"';
            },
            $html
        ) ?? $html;
    }

    /**
     * Publisher-entered article price (EUR), ignoring any in-memory advertiser markup.
     */
    public function publisherBasePrice(): float
    {
        $raw = $this->getRawOriginal('price');
        if ($raw !== null && $raw !== '') {
            return round((float) $raw, 2);
        }

        if (isset($this->original_price) && is_numeric($this->original_price)) {
            return round((float) $this->original_price, 2);
        }

        return round((float) $this->price, 2);
    }

    /**
     * Advertiser catalog/cart unit prices for this listing (hidden fee + sale floor).
     *
     * Always recomputes from the publisher base so a catalog row cannot show €40
     * while add-to-cart charges the fee-marked total.
     *
     * @return array<string, mixed>
     */
    public function advertiserCatalogPricing(?string $sensitiveType = null, int $quantity = 1): array
    {
        $forCart = clone $this;
        $forCart->setAttribute('price', $this->publisherBasePrice());

        return app(CartPricingService::class)
            ->priceForAdvertiser($forCart, $sensitiveType, $quantity);
    }

    /**
     * True when this listing belongs to the given user (dual-role publisher shopping as advertiser).
     *
     * Matches publisher_id (who listed it) and owner_id (post-verification owner).
     */
    public function isOwnedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $uid = (int) $user->id;
        if ($uid <= 0) {
            return false;
        }

        if ((int) $this->publisher_id === $uid) {
            return true;
        }

        $ownerId = (int) ($this->getAttribute('owner_id') ?? 0);

        return $ownerId > 0 && $ownerId === $uid;
    }

    /**
     * Catalog/cart IDs this user must not order.
     *
     * @return list<int>
     */
    public static function ownedIdsFor(?User $user): array
    {
        if ($user === null || (int) $user->id <= 0) {
            return [];
        }

        $uid = (int) $user->id;

        return static::query()
            ->where(function ($q) use ($uid) {
                $q->where('publisher_id', $uid);
                if (Schema::hasColumn('sites', 'owner_id')) {
                    $q->orWhere('owner_id', $uid);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Copy for own-listing catalog/cart surfaces. Does not mention the platform fee.
     */
    public static function cannotOrderOwnListingMessage(): string
    {
        return 'This is your listing — you can’t order it.';
    }

    /**
     * Prices painted on the advertiser catalog for this viewer.
     *
     * Own listings show the entered publisher price (no hidden fee) because they
     * cannot be added to cart. Everyone else sees fee-inclusive cart pricing.
     *
     * @return array{owned: bool, list: float, publisher: float, sale: float|null, sale_percent: float|null, sale_percent_nominal: float|null}
     */
    public function catalogPricesForViewer(?User $user): array
    {
        $owned = $this->isOwnedBy($user);
        $nominal = $this->activeCustomDiscountPercent();

        if ($owned) {
            $base = $this->publisherBasePrice();

            return [
                'owned' => true,
                'list' => $base,
                'publisher' => $base,
                'sale' => null,
                'sale_percent' => null,
                'sale_percent_nominal' => $nominal,
            ];
        }

        $pricing = $this->advertiserCatalogPricing();
        $list = (float) $pricing['base'];
        $sale = null;
        $salePercent = null;
        if (($pricing['discount_amount'] ?? 0) > 0
            && (float) $pricing['article_total'] < $list) {
            $sale = (float) $pricing['article_total'];
            $salePercent = (float) $pricing['discount_percent'];
        }

        return [
            'owned' => false,
            'list' => $list,
            'publisher' => (float) $pricing['publisher_price'],
            'sale' => $sale,
            'sale_percent' => $salePercent,
            'sale_percent_nominal' => $nominal,
        ];
    }

    /**
     * Offered homepage placement durations (days => fee EUR). Empty = not offered.
     *
     * @return array<int, float>
     */
    public function homepagePlacementOptions(): array
    {
        // Read the cast attribute directly. Do not gate on Schema::hasColumn —
        // Hostinger SQL patches can add columns before Schema cache refreshes,
        // and a false-negative would hide offers in catalog Site Details.
        $raw = $this->homepage_placement_prices;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $allowed = config('site_placement.homepage_days', [1, 7, 30]);
        $out = [];
        foreach ($raw as $days => $price) {
            $daysInt = (int) $days;
            if (! in_array($daysInt, $allowed, true)) {
                continue;
            }
            if (! is_numeric($price) || (float) $price < 0) {
                continue;
            }
            $out[$daysInt] = round((float) $price, 2);
        }
        ksort($out);

        return $out;
    }

    public function offersHomepagePlacement(): bool
    {
        return $this->homepagePlacementOptions() !== [];
    }

    /**
     * Longest free (€0) homepage duration, if any.
     */
    public function longestFreeHomepageDays(): ?int
    {
        $free = [];
        foreach ($this->homepagePlacementOptions() as $days => $price) {
            if ((float) $price <= 0) {
                $free[] = (int) $days;
            }
        }

        return $free === [] ? null : max($free);
    }

    /**
     * Social channels the publisher offers (always €0). Empty = not offered.
     *
     * @return list<string>
     */
    public function enabledSocialChannels(): array
    {
        // Same as homepagePlacementOptions(): trust attributes over Schema::hasColumn.
        $raw = $this->social_promotion;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $allowed = config('site_placement.social_channels', ['facebook', 'instagram', 'x']);
        $out = [];
        foreach ($allowed as $channel) {
            if (! empty($raw[$channel])) {
                $out[] = $channel;
            }
        }

        return $out;
    }

    public function offersSocialPromotion(): bool
    {
        return $this->enabledSocialChannels() !== [];
    }

    /**
     * Value safe for sites.category when the column is still VARCHAR(50).
     *
     * @param  list<string>|null  $categoryList
     */
    public static function fitCategoryColumn(string $primaryCategory, ?array $categoryList = null): string
    {
        $max = static::categoryColumnMaxLength();
        if ($max === null || strlen($primaryCategory) <= $max) {
            return $primaryCategory;
        }

        foreach ($categoryList ?? [] as $name) {
            $name = trim((string) $name);
            if ($name !== '' && strlen($name) <= $max) {
                return $name;
            }
        }

        return substr($primaryCategory, 0, $max);
    }

    public static function hasSitesColumn(string $column): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return false;
            }

            return Schema::hasColumn((new static)->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeDateAttribute(string $attribute): ?\DateTimeInterface
    {
        try {
            if (! static::hasSitesColumn($attribute)) {
                return null;
            }

            $value = $this->{$attribute};

            return $value instanceof \DateTimeInterface ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Count listings that offer homepage placement.
     * Returns 0 when Hostinger skipped the placement migration (do not WHERE a missing column).
     */
    public static function countWithHomepagePlacement(): int
    {
        if (! static::hasSitesColumn('homepage_placement_prices')) {
            return 0;
        }

        return (int) static::query()->whereNotNull('homepage_placement_prices')->count();
    }

    /**
     * Forget cached category column metadata (tests / after schema changes).
     */
    public static function flushSchemaColumnCache(): void
    {
        Cache::forget('sites_category_column_max_length');
    }

    /**
     * Max length for sites.category, or null when TEXT/unlimited.
     */
    public static function categoryColumnMaxLength(): ?int
    {
        return Cache::remember('sites_category_column_max_length', 300, function () {
            try {
                $driver = Schema::getConnection()->getDriverName();
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    $row = DB::selectOne("SHOW COLUMNS FROM `sites` WHERE Field = 'category'");
                    $type = strtolower((string) ($row->Type ?? ''));
                    if (preg_match('/^(var)?char\((\d+)\)$/', $type, $m)) {
                        return (int) $m[2];
                    }

                    return null; // text / mediumtext / longtext
                }

                // SQLite / others: treat as unlimited for marketplace listings
                return null;
            } catch (\Throwable) {
                return 50; // conservative fallback for unknown/legacy schemas
            }
        });
    }

    /**
     * Get categories as array (helper method)
     */
    public function getCategoriesListAttribute(): array
    {
        return $this->categories ?? [$this->category];
    }

    /**
     * Get categories as comma-separated string
     */
    public function getCategoriesStringAttribute(): string
    {
        $categories = $this->categories ?? [$this->category];

        return implode(', ', $categories);
    }

    /**
     * Get categories as array (handles both JSON and comma-separated strings)
     */
    public function getCategoriesArrayAttribute()
    {
        if (empty($this->categories)) {
            // Keep a single legacy niche (even with commas) as one entry — never
            // explode("Marketing, PR & Advertising") into halves.
            if (! empty($this->category)) {
                return Category::parseCatalogCategoryParam((string) $this->category);
            }

            return [];
        }

        // If it's already an array — each entry is one niche (do not split on commas).
        if (is_array($this->categories)) {
            return array_values(array_filter(array_map(
                static fn ($c) => is_scalar($c) ? trim((string) $c) : '',
                $this->categories
            ), static fn ($c) => $c !== ''));
        }

        // If it's a JSON string
        if (is_string($this->categories) && (str_starts_with($this->categories, '[') || str_starts_with($this->categories, '{'))) {
            $decoded = json_decode($this->categories, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(
                    static fn ($c) => is_scalar($c) ? trim((string) $c) : '',
                    $decoded
                ), static fn ($c) => $c !== ''));
            }
        }

        // Legacy string storage — pipe or comma list via shared catalog parser.
        if (is_string($this->categories)) {
            return Category::parseCatalogCategoryParam($this->categories);
        }

        return ! empty($this->category) ? Category::parseCatalogCategoryParam((string) $this->category) : [];
    }

    /**
     * Catalog / UI badge labels — one pill per niche; commas inside names stay intact.
     *
     * @return list<string>
     */
    public function nicheBadgeLabels(): array
    {
        $categories = is_array($this->categories) ? $this->categories : null;

        return Category::displayNicheLabels(
            $categories,
            is_string($this->category) ? $this->category : null
        );
    }

    /**
     * Check if site has a specific category
     */
    public function hasCategory($categoryName)
    {
        $categories = $this->getCategoriesArrayAttribute();

        return in_array($categoryName, $categories);
    }

    /**
     * @return array<int, string>
     */
    public function countryCodes(): array
    {
        $codes = collect($this->countries ?? [])
            ->filter()
            ->map(fn ($c) => strtolower(trim((string) $c)))
            ->all();

        if ($this->country) {
            $codes[] = strtolower(trim((string) $this->country));
        }

        return array_values(array_unique(array_filter($codes)));
    }

    public function hasMarketplaceCountry(): bool
    {
        return $this->countryCodes() !== [];
    }

    /**
     * Sites with no usable country / countries value (invisible to catalog country filters).
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeMissingMarketplaceCountry($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('country')->orWhere('country', '');
            })
            ->where(function ($q) {
                $q->whereNull('countries')
                    ->orWhere('countries', '')
                    ->orWhere('countries', '[]')
                    ->orWhere('countries', 'null');
            });
    }

    /**
     * Active listings missing a marketplace country (ops hygiene queue).
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeActiveMissingMarketplaceCountry($query)
    {
        return $query->where('active', 1)->missingMarketplaceCountry();
    }

    /**
     * @return array<int, string>
     */
    public function languageCodes(): array
    {
        $codes = collect($this->languages ?? [])
            ->filter()
            ->map(fn ($c) => strtolower(trim((string) $c)))
            ->all();

        if ($this->language) {
            $codes[] = strtolower(trim((string) $this->language));
        }

        return array_values(array_unique(array_filter($codes)));
    }

    public function acceptsMarket(string $country, string $language): bool
    {
        $country = strtolower(trim($country));
        $language = strtolower(trim($language));

        if ($country === '' || $language === '') {
            return false;
        }

        $countries = $this->countryCodes();
        $languages = $this->languageCodes();

        // If a site has no market metadata, allow any approved article (legacy listings).
        if ($countries === [] && $languages === []) {
            return true;
        }

        $countryOk = $countries === [] || in_array($country, $countries, true);
        $languageOk = $languages === [] || in_array($language, $languages, true);

        return $countryOk && $languageOk;
    }
}
