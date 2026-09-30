<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use ToleratesMissingSchema, ToleratesUnparseableDates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'mailable',
        'template_key',
        'notification_type',
        'dedupe_key',
        'audience',
        'to_email',
        'to_name',
        'from_email',
        'subject',
        'status',
        'error',
        'attempts',
        'meta',
        'sent_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Open rows for a dedupe key, newest first. A retry can leave more than
     * one pending/failed row; callers that close a send must update all of them.
     *
     * @return Collection<int, self>
     */
    public static function openByDedupe(?string $key)
    {
        if (! filled($key)) {
            return static::query()->whereRaw('0 = 1')->get();
        }

        return static::query()
            ->where('dedupe_key', $key)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->orderByDesc('id')
            ->get();
    }

    public static function findOpenByDedupe(?string $key): ?self
    {
        return static::openByDedupe($key)->first();
    }

    /**
     * A leftover failed/pending row after a real SMTP success. Campaign
     * dedupe keys are one-shot; retrying them is a second blast.
     */
    public static function latestDeliveredByDedupe(?string $key): ?self
    {
        if (! filled($key)) {
            return null;
        }

        return static::query()
            ->where('dedupe_key', $key)
            ->where('status', self::STATUS_DELIVERED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Campaign + user this log belongs to. Older sends used the generic
     * `audience_campaign|{email}|AudienceCampaignMail` key; identity then
     * lives in meta (or the one-shot `audience_campaign:{id}:user:{id}`).
     *
     * @return array{0: int, 1: int}
     */
    public static function campaignUserIds(self $log): array
    {
        $campaignId = (int) data_get($log->meta, 'campaign_id');
        $userId = (int) data_get($log->meta, 'user_id');

        foreach ([(string) $log->dedupe_key, (string) $log->template_key] as $key) {
            if (preg_match('/^audience_campaign:(\d+):user:(\d+)$/', $key, $matches)) {
                $campaignId = $campaignId > 0 ? $campaignId : (int) $matches[1];
                $userId = $userId > 0 ? $userId : (int) $matches[2];
                break;
            }
        }

        if ($campaignId > 0 && $userId > 0) {
            return [$campaignId, $userId];
        }

        return [0, 0];
    }

    /**
     * Leftover pending/failed rows for this recipient, including a
     * historical generic default key that only stores the pair in meta.
     *
     * @return Collection<int, self>
     */
    public static function openForCampaignUser(int $campaignId, int $userId)
    {
        if ($campaignId < 1 || $userId < 1) {
            return static::query()->whereRaw('0 = 1')->get();
        }

        $canonical = EmailCampaignRecipient::dedupeKey($campaignId, $userId);

        try {
            return static::query()
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
                ->where(function ($query) use ($canonical) {
                    $query->where('dedupe_key', $canonical)
                        ->orWhere('dedupe_key', 'like', 'audience_campaign|%')
                        ->orWhere('notification_type', 'audience_campaign')
                        ->orWhere('template_key', 'audience_campaign')
                        ->orWhere('mailable', 'like', '%AudienceCampaignMail%');
                })
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->filter(function (self $log) use ($campaignId, $userId, $canonical) {
                    if ((string) $log->dedupe_key === $canonical) {
                        return true;
                    }

                    [$foundCampaign, $foundUser] = static::campaignUserIds($log);

                    return $foundCampaign === $campaignId && $foundUser === $userId;
                })
                ->values();
        } catch (\Throwable) {
            return static::query()->whereRaw('0 = 1')->get();
        }
    }

    /**
     * A real SMTP success for this recipient, regardless of which dedupe
     * string that send wrote. Exact-key lookup misses a leftover generic
     * key after a later canonical send (and the reverse).
     */
    public static function latestDeliveredForCampaignUser(int $campaignId, int $userId): ?self
    {
        if ($campaignId < 1 || $userId < 1) {
            return null;
        }

        $canonical = EmailCampaignRecipient::dedupeKey($campaignId, $userId);
        $byKey = static::latestDeliveredByDedupe($canonical);
        if ($byKey) {
            return $byKey;
        }

        try {
            $hit = static::query()
                ->where('status', self::STATUS_DELIVERED)
                ->where(function ($query) use ($canonical, $campaignId, $userId) {
                    $query->where('dedupe_key', $canonical)
                        ->orWhere(function ($meta) use ($campaignId, $userId) {
                            $meta->where('meta->campaign_id', $campaignId)
                                ->where('meta->user_id', $userId);
                        });
                })
                ->orderByDesc('id')
                ->first();
            if ($hit) {
                return $hit;
            }
        } catch (\Throwable) {
        }

        // JSON meta path can miss leftover generic-key rows. Do not scan
        // the newest 100 campaign emails site-wide — a later burst hides
        // this user's delivery and isDuplicate() blasts again.
        $email = null;
        try {
            $email = User::query()->whereKey($userId)->value('email');
        } catch (\Throwable) {
        }
        $email = is_string($email) && $email !== '' ? $email : null;
        $generic = $email !== null
            ? 'audience_campaign|'.$email.'|AudienceCampaignMail'
            : null;

        try {
            $query = static::query()
                ->where('status', self::STATUS_DELIVERED)
                ->where(function ($type) {
                    $type->where('notification_type', 'audience_campaign')
                        ->orWhere('template_key', 'audience_campaign')
                        ->orWhere('mailable', 'like', '%AudienceCampaignMail%')
                        ->orWhere('dedupe_key', 'like', 'audience_campaign%');
                });
            if ($email !== null) {
                $query->where(function ($who) use ($email, $generic) {
                    $who->where('to_email', $email);
                    if ($generic !== null) {
                        $who->orWhere('dedupe_key', $generic);
                    }
                });
            }

            foreach ($query->orderByDesc('id')->cursor() as $log) {
                [$foundCampaign, $foundUser] = static::campaignUserIds($log);
                if ($foundCampaign === $campaignId && $foundUser === $userId) {
                    return $log;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Recipients with a pending Email Center row for this campaign.
     * Includes leftover generic-key retries that only store the pair in meta.
     *
     * Null means email_logs could not be read. Reclaim must fail-closed
     * instead of treating an unread table as "no pending retries".
     *
     * @return list<int>|null
     */
    public static function pendingUserIdsForCampaign(int $campaignId, ?\DateTimeInterface $updatedAfter = null): ?array
    {
        if ($campaignId < 1) {
            return [];
        }

        $ids = [];
        $prefix = 'audience_campaign:'.$campaignId.':user:';

        try {
            foreach (static::query()
                ->where('status', self::STATUS_PENDING)
                ->where(function ($query) use ($prefix) {
                    $query->where('dedupe_key', 'like', $prefix.'%')
                        ->orWhere('notification_type', 'audience_campaign')
                        ->orWhere('template_key', 'audience_campaign')
                        ->orWhere('mailable', 'like', '%AudienceCampaignMail%')
                        ->orWhere('dedupe_key', 'like', 'audience_campaign|%');
                })
                ->get(['id', 'dedupe_key', 'meta', 'notification_type', 'template_key', 'mailable', 'updated_at']) as $log) {
                if ($updatedAfter !== null
                    && $log->updated_at
                    && ! $log->updated_at->greaterThan($updatedAfter)) {
                    // Stale pending = lost retry. Expire may close it.
                    // Missing/unreadable clocks stay held (fail-closed).
                    continue;
                }
                [$foundCampaign, $foundUser] = static::campaignUserIds($log);
                if ($foundCampaign === $campaignId && $foundUser > 0) {
                    $ids[$foundUser] = true;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Recipients who already have a delivered Email Center row for this
     * campaign. Null means email_logs could not be read — reclaim/expire
     * must not treat that as “nobody was mailed”.
     *
     * @return list<int>|null
     */
    public static function deliveredUserIdsForCampaign(int $campaignId): ?array
    {
        if ($campaignId < 1) {
            return [];
        }

        $ids = [];
        $prefix = 'audience_campaign:'.$campaignId.':user:';

        try {
            foreach (static::query()
                ->where('status', self::STATUS_DELIVERED)
                ->where(function ($query) use ($prefix) {
                    $query->where('dedupe_key', 'like', $prefix.'%')
                        ->orWhere('notification_type', 'audience_campaign')
                        ->orWhere('template_key', 'audience_campaign')
                        ->orWhere('mailable', 'like', '%AudienceCampaignMail%')
                        ->orWhere('dedupe_key', 'like', 'audience_campaign|%');
                })
                ->get(['id', 'dedupe_key', 'meta', 'notification_type', 'template_key', 'mailable']) as $log) {
                [$foundCampaign, $foundUser] = static::campaignUserIds($log);
                if ($foundCampaign === $campaignId && $foundUser > 0) {
                    $ids[$foundUser] = true;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @return array{sent_today: int, pending: int, failed: int, delivered: int, pending_today: int, failed_today: int}
     */
    public static function dashboardKpis(): array
    {
        $empty = [
            'sent_today' => 0,
            'pending' => 0,
            'failed' => 0,
            'delivered' => 0,
            'pending_today' => 0,
            'failed_today' => 0,
        ];

        $today = now()->toDateString();
        try {
            $row = static::query()
                ->toBase()
                ->selectRaw(
                    'SUM(CASE WHEN date(coalesce(sent_at, created_at)) = ? THEN 1 ELSE 0 END) as sent_today,
                     SUM(CASE WHEN status = ? AND date(coalesce(sent_at, created_at)) = ? THEN 1 ELSE 0 END) as delivered_today,
                     SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                     SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count,
                     SUM(CASE WHEN status = ? AND date(coalesce(sent_at, created_at)) = ? THEN 1 ELSE 0 END) as pending_today,
                     SUM(CASE WHEN status = ? AND date(coalesce(sent_at, created_at)) = ? THEN 1 ELSE 0 END) as failed_today',
                    [
                        $today,
                        self::STATUS_DELIVERED, $today,
                        self::STATUS_PENDING,
                        self::STATUS_FAILED,
                        self::STATUS_PENDING, $today,
                        self::STATUS_FAILED, $today,
                    ]
                )
                ->first();
        } catch (\Throwable) {
            return $empty;
        }

        return [
            'sent_today' => (int) ($row->sent_today ?? 0),
            'pending' => (int) ($row->pending_count ?? 0),
            'failed' => (int) ($row->failed_count ?? 0),
            'delivered' => (int) ($row->delivered_today ?? 0),
            'pending_today' => (int) ($row->pending_today ?? 0),
            'failed_today' => (int) ($row->failed_today ?? 0),
        ];
    }
}
