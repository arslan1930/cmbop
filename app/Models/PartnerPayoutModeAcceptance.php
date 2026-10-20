<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPayoutModeAcceptance extends Model
{
    public const KEEP_LIVE = 'keep_live_promise';

    public const GUARANTEE = 'guarantee_conditions';

    protected $fillable = [
        'partner_id', 'user_id', 'terms_type', 'terms_version', 'terms_text_sha256',
        'keep_live_months', 'accepted_at', 'ip', 'user_agent', 'method',
        'approved_by', 'approved_at', 'revoked_at', 'revoked_reason',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isActiveKeepLive(): bool
    {
        return $this->terms_type === self::KEEP_LIVE
            && $this->revoked_at === null
            && $this->approved_at !== null;
    }
}
