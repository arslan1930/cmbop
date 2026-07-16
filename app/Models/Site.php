<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

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
        'owner_id'
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
    ];

    public function enrichmentRuns()
    {
        return $this->hasMany(SiteEnrichmentRun::class);
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