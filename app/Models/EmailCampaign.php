<?php

namespace App\Models;

use App\Jobs\SendEmailCampaignJob;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EmailCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name',
        'subject',
        'body_html',
        'audience',
        'selected_user_ids',
        'cta_label',
        'cta_url',
        'recipients_count',
        'sent_count',
        'skipped_count',
        'status',
        'respect_preferences',
        'created_by',
        'sent_at',
    ];

    protected $casts = [
        'selected_user_ids' => 'array',
        'respect_preferences' => 'boolean',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'skipped_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    public function recountRecipientTotals(): void
    {
        $sent = $this->recipients()
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_QUEUED,
                EmailCampaignRecipient::STATUS_DELIVERED,
            ])
            ->count();
        $skipped = $this->recipients()
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_SKIPPED,
                EmailCampaignRecipient::STATUS_FAILED,
            ])
            ->count();

        $payload = [
            'sent_count' => $sent,
            'skipped_count' => $skipped,
        ];

        // Finalize treats queued mail as sent. After a retry or a late
        // failure, keep the terminal status honest against those totals.
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_FAILED], true)) {
            $payload['status'] = $sent > 0 ? self::STATUS_SENT : self::STATUS_FAILED;
        }

        $this->update($payload);
    }

    public static function labelForAudience(?string $audience): string
    {
        return AudienceInventoryService::label($audience);
    }

    public function audienceLabel(): string
    {
        return self::labelForAudience($this->audience);
    }

    public static function failStreakKey(int $campaignId): string
    {
        return 'email-campaign:fail-streak:'.$campaignId;
    }

    public function currentFailStreak(): int
    {
        try {
            return max(0, (int) Cache::get(self::failStreakKey((int) $this->id), 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function rememberFailStreak(int $streak): int
    {
        $streak = max(0, $streak);
        try {
            Cache::put(self::failStreakKey((int) $this->id), $streak, now()->addHours(6));
        } catch (\Throwable) {
        }

        return $streak;
    }

    public function clearFailStreak(): void
    {
        try {
            Cache::forget(self::failStreakKey((int) $this->id));
        } catch (\Throwable) {
        }
    }

    /**
     * Re-queue campaigns whose worker died (OOM, deploy, drain timeout)
     * instead of leaving them stuck on queued/sending.
     */
    public static function recoverStalled(int $staleMinutes = 2): int
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $lock = null;
        try {
            $store = Cache::store()->getStore();
            if ($store instanceof LockProvider) {
                $lock = Cache::store()->lock('email-campaigns:recover-stalled', 15);
                if (! $lock->get()) {
                    return 0;
                }
            }
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            return self::recoverStalledLocked($staleMinutes);
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable) {
            }
        }
    }

    protected static function recoverStalledLocked(int $staleMinutes): int
    {
        self::expireOrphanedQueuedRecipients();

        $stale = now()->subMinutes(max(1, $staleMinutes));
        $dispatched = 0;

        $ids = static::query()
            ->where(function ($query) use ($stale) {
                $query->where(function ($queued) use ($stale) {
                    $queued->where('status', self::STATUS_QUEUED)
                        ->where('updated_at', '<=', $stale);
                })->orWhere(function ($sending) use ($stale) {
                    $sending->where('status', self::STATUS_SENDING)
                        ->where('updated_at', '<=', $stale);
                });
            })
            ->pluck('id');

        foreach ($ids as $id) {
            $campaign = static::query()->find($id);
            if (! $campaign) {
                continue;
            }

            if ($campaign->status === self::STATUS_SENDING
                && ! $campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                    ->exists()) {
                $campaign->clearFailStreak();
                $campaign->recountRecipientTotals();
                $campaign->refresh();
                $campaign->update([
                    'status' => $campaign->sent_count > 0
                        ? self::STATUS_SENT
                        : self::STATUS_FAILED,
                    'sent_at' => $campaign->sent_at ?? now(),
                ]);

                continue;
            }

            if ($campaign->status === self::STATUS_SENDING
                && $campaign->currentFailStreak() >= SendEmailCampaignJob::MAX_FAIL_STREAK) {
                EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaign->id)
                    ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                    ->update([
                        'status' => EmailCampaignRecipient::STATUS_FAILED,
                        'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
                    ]);
                $campaign->clearFailStreak();
                $campaign->recountRecipientTotals();
                $campaign->update([
                    'status' => self::STATUS_FAILED,
                    'sent_at' => $campaign->sent_at ?? now(),
                ]);

                continue;
            }

            try {
                SendEmailCampaignJob::dispatch((int) $id);
                $dispatched++;
            } catch (\Throwable $e) {
                Log::warning('Stalled campaign re-queue failed', [
                    'campaign_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dispatched;
    }

    /**
     * A timeout can claim pending → queued and die before Mail::send()
     * inserts the mailable. Those rows count as sent forever. After the
     * campaign stale window they are skipped so recount can go failed.
     */
    protected static function expireOrphanedQueuedRecipients(): void
    {
        $hours = (int) config('email_notifications.campaign_max_age_hours', 72);
        if ($hours <= 0 || ! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
            return;
        }

        $cutoff = now()->subHours($hours);
        $campaignIds = EmailCampaignRecipient::query()
            ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
            ->whereNull('email_log_id')
            ->where('updated_at', '<=', $cutoff)
            ->pluck('email_campaign_id')
            ->unique()
            ->filter()
            ->all();

        if ($campaignIds === []) {
            return;
        }

        EmailCampaignRecipient::query()
            ->whereIn('email_campaign_id', $campaignIds)
            ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
            ->whereNull('email_log_id')
            ->where('updated_at', '<=', $cutoff)
            ->update([
                'status' => EmailCampaignRecipient::STATUS_SKIPPED,
                'skip_reason' => EmailCampaignRecipient::SKIP_STALE,
            ]);

        foreach ($campaignIds as $id) {
            static::query()->find($id)?->recountRecipientTotals();
        }
    }
}
