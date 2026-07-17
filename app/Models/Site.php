<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Site extends Model
{
    protected $fillable = [
        'publisher_id',
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
    ];

    protected $casts = [
        'verified' => 'boolean',
        'active' => 'boolean',
        'sponsored' => 'boolean',
        'partner_material' => 'boolean',
        'as_you_prefer' => 'boolean',
        'da' => 'integer',
        'dr' => 'integer',
        'traffic' => 'integer',
        'price' => 'decimal:2',
        'publication_time' => 'string',
        'sensitive_prices' => 'array', // if stored as JSON
        'categories' => 'array', // NEW - cast categories to array
        'countries' => 'array',
        'languages' => 'array',
        'site_image' => 'string', // ADDED - cast site_image to string
        'metrics_manual' => 'boolean',
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

    public function completedOrdersLabel(): string
    {
        $count = (int) ($this->completed_orders_count ?? 0);
        if ($count < 1) {
            return 'No completed orders yet';
        }

        return $count.' completed '.($count === 1 ? 'order' : 'orders');
    }

    /**
     * Completion rate among terminal orders (completed vs cancelled).
     * Returns null when there is no history yet.
     */
    public function completionRatePercent(): ?int
    {
        $completed = (int) ($this->completed_orders_count ?? 0);
        $cancelled = (int) ($this->cancelled_orders_count
            ?? OrderItem::query()
                ->where('site_id', $this->id)
                ->whereHas('order', fn ($q) => $q->where('status', 'cancelled'))
                ->count());

        $total = $completed + $cancelled;
        if ($total < 1) {
            return null;
        }

        return (int) round(($completed / $total) * 100);
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
            $at = $raw instanceof \Illuminate\Support\Carbon
                ? $raw
                : \Illuminate\Support\Carbon::parse($raw);
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
                $query->where('da', '>=', (int)$min);
            })
            ->when($filters['da_max'] ?? null, function ($query, $max) {
                $query->where('da', '<=', (int)$max);
            })
            ->when($filters['dr_min'] ?? null, function ($query, $min) {
                $query->where('dr', '>=', (int)$min);
            })
            ->when($filters['dr_max'] ?? null, function ($query, $max) {
                $query->where('dr', '<=', (int)$max);
            })
            ->when($filters['traffic_min'] ?? null, function ($query, $min) {
                $query->where('traffic', '>=', (int)$min);
            })
            ->when($filters['traffic_max'] ?? null, function ($query, $max) {
                $query->where('traffic', '<=', (int)$max);
            })
            ->when($filters['price_min'] ?? null, function ($query, $min) {
                $query->where('price', '>=', (float)$min);
            })
            ->when($filters['price_max'] ?? null, function ($query, $max) {
                $query->where('price', '<=', (float)$max);
            })
            ->when($filters['country'] ?? null, function ($query, $country) {
                $codes = is_array($country) ? $country : [$country];
                $query->where(function ($q) use ($codes) {
                    foreach ($codes as $code) {
                        $code = strtolower(trim((string) $code));
                        if ($code === '') {
                            continue;
                        }
                        $q->orWhere('country', $code)
                          ->orWhereJsonContains('countries', $code);
                    }
                });
            })
            ->when($filters['language'] ?? null, function ($query, $language) {
                $codes = is_array($language) ? $language : [$language];
                $query->where(function ($q) use ($codes) {
                    foreach ($codes as $code) {
                        $code = strtolower(trim((string) $code));
                        if ($code === '') {
                            continue;
                        }
                        $q->orWhere('language', $code)
                          ->orWhereJsonContains('languages', $code);
                    }
                });
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
     * Accessor for formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return '$' . number_format($this->price, 2);
    }

    /**
     * Accessor for full image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        if ($this->site_image) {
            return asset('storage/' . $this->site_image);
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
    public function getMetricsUpdatedAtAttribute(): ?\Illuminate\Support\Carbon
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
        $codes = $this->countryCodes();

        return $codes[0] ?? null;
    }

    public function primaryLanguageCode(): ?string
    {
        $codes = $this->languageCodes();

        return $codes[0] ?? null;
    }

    /**
     * Check if site has good metrics.
     */
    public function hasGoodMetrics(): bool
    {
        return $this->da >= 30 && $this->dr >= 30 && $this->traffic >= 10000;
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
            $this->{$column} = $value;
        }
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
        return Schema::hasColumn((new static)->getTable(), $column);
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
            return !empty($this->category) ? [$this->category] : [];
        }
        
        // If it's already an array
        if (is_array($this->categories)) {
            return $this->categories;
        }
        
        // If it's a JSON string
        if (is_string($this->categories) && (str_starts_with($this->categories, '[') || str_starts_with($this->categories, '{'))) {
            $decoded = json_decode($this->categories, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        // If it's a comma-separated string
        if (is_string($this->categories) && str_contains($this->categories, ',')) {
            return array_map('trim', explode(',', $this->categories));
        }
        
        // Single value
        return !empty($this->categories) ? [$this->categories] : (!empty($this->category) ? [$this->category] : []);
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