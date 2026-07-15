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
        'turnaround_time',
        'country',
        'language',
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
        'site_image' => 'string', // ADDED - cast site_image to string
    ];

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
                $query->where('country', $country);
            })
            ->when($filters['language'] ?? null, function ($query, $language) {
                $query->where('language', $language);
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
}