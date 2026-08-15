<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaignRecipient extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const SKIP_PREFERENCE = 'preference';

    public const SKIP_UNVERIFIED = 'unverified';

    public const SKIP_ERROR = 'error';

    public const SKIP_STALE = 'stale';

    public const SKIP_DISABLED = 'disabled';

    protected $fillable = [
        'email_campaign_id',
        'user_id',
        'email',
        'status',
        'skip_reason',
        'email_log_id',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public static function dedupeKey(int $campaignId, int $userId): string
    {
        return 'audience_campaign:'.$campaignId.':user:'.$userId;
    }
}
