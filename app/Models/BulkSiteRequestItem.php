<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSiteRequestItem extends Model
{
    protected $fillable = [
        'bulk_site_request_id',
        'site_url',
        'domain',
        'price',
        'site_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function bulkRequest(): BelongsTo
    {
        return $this->belongsTo(BulkSiteRequest::class, 'bulk_site_request_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Pending URL+price row on a non-cancelled batch that already claims this domain.
     */
    public static function findOccupyingPendingDomain(string $domain, bool $lock = false): ?self
    {
        $candidates = Site::domainLookupCandidates($domain);
        if ($candidates === []) {
            return null;
        }

        $normalized = Site::normalizeMarketplaceDomain($domain);
        $query = static::query()
            ->whereNull('site_id')
            ->where(function ($q) use ($candidates, $normalized) {
                $q->whereIn('domain', $candidates);
                if ($normalized !== '') {
                    $escaped = addcslashes($normalized, '%_\\');
                    $q->orWhere('domain', 'like', $escaped.':%')
                        ->orWhere('domain', 'like', 'www.'.$escaped.':%');
                }
            })
            ->whereHas('bulkRequest', function ($bulk) {
                $bulk->where('status', '!=', BulkSiteRequest::STATUS_CANCELLED);
            })
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public static function occupyingPendingDomainMessage(string $domain, bool $lock = false): ?string
    {
        if (! static::findOccupyingPendingDomain($domain, $lock)) {
            return null;
        }

        return "Already in an open bulk request: {$domain}";
    }
}
