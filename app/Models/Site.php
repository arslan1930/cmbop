<?php

namespace App\Models;

use App\Services\Catalog\CatalogCountryInventory;
use App\Services\Catalog\CatalogLanguageFilter;
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
        'verified',
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
        'agency_site_import_id',
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
        'categories' => 'array',
        'countries' => 'array',
        'languages' => 'array',
        'site_image' => 'string',
        'metrics_manual' => 'boolean',
        'archived_at' => 'datetime',
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
    ];

    public function awaitsPublisherDetails(): bool
    {
        return $this->onboarding_status === self::ONBOARDING_AWAITING_DETAILS;
    }

    public function hasDetailsComplete(): bool
    {
        return $this->onboarding_status === self::ONBOARDING_DETAILS_COMPLETE;
    }

    /**
     * Bulk draft still owned by the publisher (filling forms or reviewing before submit).
     */
    public function isPendingPublisherBulkSubmit(): bool
    {
        return $this->awaitsPublisherDetails() || $this->hasDetailsComplete();
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
        $ok = $this->markReadyForAdminReview();

        if ($ok && $this->bulk_site_request_id) {
            $this->bulkSiteRequest?->refreshProgressStatus();
        }

        return $ok;
    }

    /**
     * Move a bulk draft into the admin review queue.
     * Hostinger may still have a narrow ENUM/VARCHAR that rejects ready_for_review;
     * NULL is treated as queue-eligible by needsAdminReview().
     */
    public function markReadyForAdminReview(): bool
    {
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

    public function markDetailsComplete(): bool
    {
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
                'hint' => 'Run database/sql/fix_sites_onboarding_status.sql in phpMyAdmin',
            ]);

            $this->onboarding_status = $previous;

            return false;
        }
    }

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
     * Marketing may delete pending / not-live sites only (never verified or active portal listings).
     */
    public function canBeDeletedByMarketing(): bool
    {
        return ! (bool) $this->verified && ! (bool) $this->active;
    }

    public function isReadyForAdminReview(): bool
    {
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
            && $this->isAcceptedByPublisher();
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

        return $query;
    }

    public function enrichmentRuns()
    {
        return $this->hasMany(SiteEnrichmentRun::class);
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
        $count = OrderItem::query()
            ->where('site_id', $siteId)
            ->whereHas('order', function ($q) {
                $q->where('status', 'completed');
            })
            ->count();

        static::query()->where('id', $siteId)->update([
            'completed_orders_count' => $count,
        ]);
    }

    public function isFeatured(): bool
    {
        if (! static::hasSitesColumn('featured_until')) {
            return false;
        }

        return $this->featured_until !== null && $this->featured_until->isFuture();
    }

    public function hasActiveCustomDiscount(): bool
    {
        if (! static::hasSitesColumn('custom_discount_percent')) {
            return false;
        }

        if (! $this->custom_discount_percent || ! $this->custom_discount_ends_at) {
            return false;
        }

        $startsOk = ! $this->custom_discount_starts_at || $this->custom_discount_starts_at->lte(now());

        return $startsOk && $this->custom_discount_ends_at->isFuture();
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

    public function isFromAgencyCsvImport(): bool
    {
        if (! static::hasSitesColumn('agency_site_import_id')) {
            return false;
        }

        return (int) ($this->agency_site_import_id ?? 0) > 0;
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

    /**
     * Sites the publisher has accepted (or created themselves).
     */
    public function scopeAcceptedByPublisher($query)
    {
        if (! static::hasSitesColumn('publisher_accepted_at')) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNotNull('publisher_accepted_at')
                ->orWhereNull('assigned_by_user_id');
        });
    }

    /**
     * Staff-assigned sites awaiting Accept/Decline.
     */
    public function scopePendingPublisherAcceptance($query)
    {
        if (! static::hasSitesColumn('publisher_accepted_at')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNull('publisher_accepted_at')
            ->whereNotNull('assigned_by_user_id');
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
     * Accessor for full image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        if ($this->site_image) {
            return asset('storage/'.$this->site_image);
        }

        return null;
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        $path = $this->screenshot_path ?: $this->site_image;
        if (! $path) {
            return null;
        }

        return asset('storage/'.$path);
    }

    public function getScreenshotThumbUrlAttribute(): ?string
    {
        $path = $this->screenshot_thumb_path ?: $this->screenshot_path ?: $this->site_image;
        if (! $path) {
            return null;
        }

        return asset('storage/'.$path);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->favicon_path) {
            return asset('storage/'.$this->favicon_path);
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
