<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerAutoApproveRule extends Model
{
    public const ACTION_REVIEW = 'send_to_review';

    public const ACTION_LIVE = 'go_live';

    protected $fillable = [
        'name',
        'priority',
        'partner_id',
        'categories_any',
        'categories_none',
        'min_dr',
        'min_da',
        'min_monthly_traffic',
        'countries',
        'languages',
        'max_partner_rate_eur',
        'link_types',
        'require_sample_post',
        'reject_sensitive_only',
        'action',
        'pricing_rule_id',
        'active',
        'created_by',
    ];

    protected $casts = [
        'categories_any' => 'array',
        'categories_none' => 'array',
        'countries' => 'array',
        'languages' => 'array',
        'link_types' => 'array',
        'min_dr' => 'integer',
        'min_da' => 'integer',
        'min_monthly_traffic' => 'integer',
        'max_partner_rate_eur' => 'decimal:2',
        'require_sample_post' => 'boolean',
        'reject_sensitive_only' => 'boolean',
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PartnerPricingRule::class, 'pricing_rule_id');
    }
}
