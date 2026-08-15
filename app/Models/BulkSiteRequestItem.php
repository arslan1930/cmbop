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
     * A pending URL+price row on an open (not cancelled) bulk that already
     * claims this domain, including www / port twins.
     */
    public static function findOccupyingPendingDomain(
        string $domain,
        ?int $exceptBulkId = null,
        bool $lock = false
    ): ?self {
        $candidates = Site::domainLookupCandidates($domain);
        if ($candidates === []) {
            return null;
        }

        $normalized = Site::normalizeMarketplaceDomain($domain);
        $query = static::query()
            ->whereNull('site_id')
            ->where(function ($q) use ($candidates, $normalized) {
                if ($candidates !== []) {
                    $q->whereIn('domain', $candidates);
                }
                if ($normalized !== '') {
                    $escaped = addcslashes($normalized, '%_\\');
                    $q->orWhere('domain', 'like', $escaped.':%')
                        ->orWhere('domain', 'like', 'www.'.$escaped.':%');
                }
            })
            ->whereHas('bulkRequest', function ($bulk) {
                $bulk->where('status', '!=', BulkSiteRequest::STATUS_CANCELLED);
            });

        if ($exceptBulkId) {
            $query->where('bulk_site_request_id', '!=', $exceptBulkId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('id')->first();
    }

    public static function occupyingPendingDomainMessage(
        string $domain,
        ?int $exceptBulkId = null,
        bool $lock = false
    ): ?string {
        $item = static::findOccupyingPendingDomain($domain, $exceptBulkId, $lock);
        if (! $item) {
            return null;
        }

        $label = Site::normalizeMarketplaceDomain((string) $item->domain);
        if ($label === '') {
            $label = Site::normalizeMarketplaceDomain($domain);
        }

        return 'Already in an open bulk request: '.($label !== '' ? $label : $domain);
    }
}
