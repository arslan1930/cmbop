<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public const SKIP_STAFF = 'staff';

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

    /**
     * In-flight or failed rows a real SMTP success may close.
     * Skipped rows are not listed here — only an expire-stale skip
     * may be overwritten (see openForDelivery).
     *
     * @return list<string>
     */
    public static function statusesOpenForDelivery(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * A real SMTP success (or duplicate-of-a-real-send) may close
     * pending / queued / failed, and a skipped row only when expire
     * parked it as stale while the mailer was still in flight.
     * Preference, disabled, unverified, and staff skips stay skipped.
     */
    public function scopeOpenForDelivery(Builder $query): Builder
    {
        return $query->where(function (Builder $open) {
            $open->whereIn('status', self::statusesOpenForDelivery())
                ->orWhere(function (Builder $stale) {
                    $stale->where('status', self::STATUS_SKIPPED)
                        ->where('skip_reason', self::SKIP_STALE);
                });
        });
    }
}
