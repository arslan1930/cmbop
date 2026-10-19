<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerCatalogItem extends Model
{
    public const STATUS_UNUSED = 'unused';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_LIVE = 'live';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REMOVED = 'removed_at_source';

    protected $fillable = [
        'partner_id',
        'site_id',
        'last_job_id',
        'matched_rule_id',
        'pricing_rule_id',
        'listing_status',
        'pause_reason',
        'block_reason',
        'domain',
        'site_url',
        'site_name',
        'description',
        'contact_email',
        'contact_name',
        'country',
        'language',
        'niches',
        'raw_niches',
        'da',
        'dr',
        'traffic',
        'turnaround_days',
        'turnaround_time',
        'publication_time',
        'link_type',
        'sponsored_label',
        'example_url',
        'partner_rate_currency',
        'partner_rate_publish',
        'partner_rate_writing',
        'partner_rate_sensitive_extra',
        'partner_rate_publish_eur',
        'partner_rate_writing_eur',
        'partner_rate_sensitive_extra_eur',
        'fx_rate',
        'fx_rate_date',
        'partner_rate_version',
        'partner_rate_effective_from',
        'slb_price_publish_eur',
        'slb_price_writing_eur',
        'slb_price_sensitive_extra_eur',
        'slb_price_source',
        'slb_jitter',
        'slb_price_overridden',
        'margin_override',
        'margin_override_reason',
        'duplicate',
        'duplicate_site_id',
        'unreachable',
        'last_reachable_at',
        'casino',
        'unmapped_niche',
        'niches_truncated',
        'below_margin_floor',
        'removed_at_source_at',
        'flags',
        'source_hash',
    ];

    protected $casts = [
        'niches' => 'array',
        'raw_niches' => 'array',
        'flags' => 'array',
        'source_hash' => 'array',
        'da' => 'integer',
        'dr' => 'integer',
        'traffic' => 'integer',
        'turnaround_days' => 'integer',
        'partner_rate_publish' => 'decimal:2',
        'partner_rate_writing' => 'decimal:2',
        'partner_rate_sensitive_extra' => 'decimal:2',
        'partner_rate_publish_eur' => 'decimal:2',
        'partner_rate_writing_eur' => 'decimal:2',
        'partner_rate_sensitive_extra_eur' => 'decimal:2',
        'fx_rate' => 'decimal:6',
        'fx_rate_date' => 'date',
        'partner_rate_version' => 'integer',
        'partner_rate_effective_from' => 'datetime',
        'slb_price_publish_eur' => 'decimal:2',
        'slb_price_writing_eur' => 'decimal:2',
        'slb_price_sensitive_extra_eur' => 'decimal:2',
        'slb_jitter' => 'decimal:6',
        'slb_price_overridden' => 'boolean',
        'margin_override' => 'boolean',
        'duplicate' => 'boolean',
        'unreachable' => 'boolean',
        'last_reachable_at' => 'datetime',
        'casino' => 'boolean',
        'unmapped_niche' => 'boolean',
        'niches_truncated' => 'boolean',
        'below_margin_floor' => 'boolean',
        'removed_at_source_at' => 'datetime',
    ];

    /**
     * Advertiser retail fields. Never serialised to the publisher/partner side.
     *
     * @var list<string>
     */
    protected $hidden = [
        'slb_price_publish_eur',
        'slb_price_writing_eur',
        'slb_price_sensitive_extra_eur',
        'slb_price_source',
        'slb_jitter',
        'slb_price_overridden',
        'margin_override',
        'margin_override_reason',
        'below_margin_floor',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PartnerCatalogItemEvent::class, 'item_id');
    }

    public function rateChanges(): HasMany
    {
        return $this->hasMany(PartnerSiteRateChange::class, 'item_id');
    }

    public function matchedRule(): BelongsTo
    {
        return $this->belongsTo(PartnerAutoApproveRule::class, 'matched_rule_id');
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PartnerPricingRule::class, 'pricing_rule_id');
    }

    public function isLive(): bool
    {
        return $this->listing_status === self::STATUS_LIVE;
    }

    public function isBlocked(): bool
    {
        return $this->listing_status === self::STATUS_BLOCKED || $this->casino;
    }

    public function commissionPublishEur(): ?float
    {
        $slb = $this->slb_price_publish_eur;
        if ($slb === null) {
            return null;
        }

        return round((float) $slb - (float) $this->partner_rate_publish_eur, 2);
    }

    public function marginPercent(): ?float
    {
        $slb = (float) ($this->slb_price_publish_eur ?? 0);
        if ($slb <= 0) {
            return null;
        }
        $commission = $this->commissionPublishEur();
        if ($commission === null) {
            return null;
        }

        return round(($commission / $slb) * 100, 2);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('listing_status', $status);
    }
}
