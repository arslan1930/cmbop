<?php

namespace App\Models;

use App\Jobs\SendEmailCampaignJob;
use App\Services\AudienceInventoryService;
use App\Support\MailJobPayload;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * @return array{pending: int, queued: int, delivered: int, failed: int, skipped: int}
     */
    public function recipientStatusCounts(): array
    {
        $rows = $this->recipients()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) ($rows[EmailCampaignRecipient::STATUS_PENDING] ?? 0),
            'queued' => (int) ($rows[EmailCampaignRecipient::STATUS_QUEUED] ?? 0),
            'delivered' => (int) ($rows[EmailCampaignRecipient::STATUS_DELIVERED] ?? 0),
            'failed' => (int) ($rows[EmailCampaignRecipient::STATUS_FAILED] ?? 0),
            'skipped' => (int) ($rows[EmailCampaignRecipient::STATUS_SKIPPED] ?? 0),
        ];
    }

    public function hasInFlightRecipients(): bool
    {
        return $this->recipients()
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_PENDING,
                EmailCampaignRecipient::STATUS_QUEUED,
            ])
            ->exists();
    }

    public function recountRecipientTotals(): void
    {
        $counts = $this->recipientStatusCounts();
        $inFlight = $counts['pending'] + $counts['queued'];

        $payload = [
            'sent_count' => $counts['queued'] + $counts['delivered'],
            'skipped_count' => $counts['skipped'] + $counts['failed'],
        ];

        // queued counts toward progress, but the campaign is only "sent"
        // after at least one real delivery and nothing left in flight.
        // A lost mail job or Mail::queue() must not flip sending → sent.
        if ($inFlight > 0) {
            if ($this->status === self::STATUS_SENT) {
                $payload['status'] = self::STATUS_SENDING;
                $payload['sent_at'] = null;
            }
        } elseif (in_array($this->status, [self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_FAILED], true)) {
            if ($counts['delivered'] > 0) {
                $payload['status'] = self::STATUS_SENT;
                $payload['sent_at'] = $this->sent_at ?? now();
            } else {
                $payload['status'] = self::STATUS_FAILED;
                $payload['sent_at'] = $this->sent_at ?? now();
            }
        }

        $this->update($payload);
    }

    /**
     * Finish the campaign only when no recipient is still pending or queued.
     */
    public function finalizeIfIdle(): bool
    {
        $this->recountRecipientTotals();
        $this->refresh();

        if ($this->hasInFlightRecipients()) {
            return false;
        }

        $delivered = $this->recipients()
            ->where('status', EmailCampaignRecipient::STATUS_DELIVERED)
            ->count();

        $this->update([
            'status' => $delivered > 0 ? self::STATUS_SENT : self::STATUS_FAILED,
            'sent_at' => $this->sent_at ?? now(),
        ]);

        return true;
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
        self::reconcileQueuedRecipientsFromLogs($staleMinutes);
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
                })->orWhere(function ($sentLie) use ($stale) {
                    $sentLie->where('status', self::STATUS_SENT)
                        ->where('updated_at', '<=', $stale)
                        ->whereHas('recipients', function ($recipients) {
                            $recipients->whereIn('status', [
                                EmailCampaignRecipient::STATUS_PENDING,
                                EmailCampaignRecipient::STATUS_QUEUED,
                            ]);
                        });
                });
            })
            ->pluck('id');

        foreach ($ids as $id) {
            $campaign = static::query()->find($id);
            if (! $campaign) {
                continue;
            }

            if ($campaign->status === self::STATUS_SENT && $campaign->hasInFlightRecipients()) {
                $campaign->finalizeIfIdle();
                $campaign->refresh();
                if ($campaign->status !== self::STATUS_SENDING) {
                    continue;
                }
            }

            if ($campaign->status === self::STATUS_SENDING
                && ! $campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                    ->exists()) {
                if ($campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                    ->exists()) {
                    // Mail is still in flight (or the job was lost). Do not
                    // pretend the campaign sent. Touch so recover does not
                    // keep selecting this row on every page view.
                    $campaign->touch();

                    continue;
                }

                $campaign->clearFailStreak();
                $campaign->finalizeIfIdle();

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
                $campaign->update([
                    'status' => self::STATUS_FAILED,
                    'sent_at' => $campaign->sent_at ?? now(),
                ]);
                $campaign->refresh();
                $campaign->recountRecipientTotals();

                continue;
            }

            try {
                if (self::hasQueuedSendJob((int) $id)) {
                    $campaign->touch();

                    continue;
                }
                SendEmailCampaignJob::dispatch((int) $id);
                $campaign->touch();
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
     * Recover used to dispatch without bumping updated_at, so a backed-up
     * emails queue made every page view / drain enqueue another send job.
     *
     * The send job used to ride `queue.default` while this check only looked
     * at MAIL_QUEUE_CONNECTION. Scan both so a mismatch cannot flood.
     * Database-queue rows JSON-escape the serialized command.
     */
    protected static function hasQueuedSendJob(int $campaignId): bool
    {
        if ($campaignId < 1) {
            return false;
        }

        foreach (self::sendJobQueueConnections() as $connection) {
            try {
                if ($connection === 'sync'
                    || config("queue.connections.{$connection}.driver") !== 'database') {
                    continue;
                }

                $table = (string) config("queue.connections.{$connection}.table", 'jobs');
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $payloads = DB::table($table)
                    ->where('payload', 'like', '%SendEmailCampaignJob%')
                    ->pluck('payload');

                foreach ($payloads as $payload) {
                    if (MailJobPayload::containsSendCampaignJob((string) $payload, $campaignId)) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // A broken first connection must not hide a job on the other.
            }
        }

        return false;
    }

    /**
     * The send job pins onConnection() to preferredSendJobConnection()
     * (mail first, otherwise queue.default). Check both so a sync side
     * cannot hide a database-queued send job on the other connection.
     *
     * @return list<string>
     */
    protected static function sendJobQueueConnections(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('email_notifications.queue_connection', config('queue.default')),
            (string) config('queue.default'),
        ])));
    }

    /**
     * Connections that can actually store campaign / mail jobs. Sync mail
     * with a database app queue still has SendEmailCampaignJob rows to drain.
     *
     * @return list<string>
     */
    public static function drainableQueueConnections(): array
    {
        $ready = [];

        foreach (self::sendJobQueueConnections() as $connection) {
            if ($connection === '' || $connection === 'sync') {
                continue;
            }

            if (config("queue.connections.{$connection}.driver") === 'database') {
                try {
                    $table = (string) config("queue.connections.{$connection}.table", 'jobs');
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            $ready[] = $connection;
        }

        return array_values(array_unique($ready));
    }

    /**
     * Prefer the mail connection when it can store jobs, otherwise the first
     * drainable app connection. Null means both are sync (run inline).
     */
    public static function preferredSendJobConnection(): ?string
    {
        $drainable = self::drainableQueueConnections();
        if ($drainable === []) {
            return null;
        }

        $mail = (string) config('email_notifications.queue_connection', '');
        if ($mail !== '' && in_array($mail, $drainable, true)) {
            return $mail;
        }

        return $drainable[0];
    }

    /**
     * LogSentEmail can persist a delivered/failed row and still miss the
     * recipient FK. Re-attach those before expire treats them as stale.
     */
    protected static function reconcileQueuedRecipientsFromLogs(int $staleMinutes = 2): void
    {
        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())
                || ! Schema::hasTable((new EmailLog)->getTable())) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $cutoff = now()->subMinutes(max(1, $staleMinutes));
        $rows = EmailCampaignRecipient::query()
            ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
            ->whereNull('email_log_id')
            ->where('updated_at', '<=', $cutoff)
            ->get(['id', 'email_campaign_id', 'user_id', 'updated_at']);

        if ($rows->isEmpty()) {
            return;
        }

        $keys = $rows->map(fn (EmailCampaignRecipient $row) => EmailCampaignRecipient::dedupeKey(
            (int) $row->email_campaign_id,
            (int) $row->user_id
        ))->unique()->values()->all();

        $logs = EmailLog::query()
            ->whereIn('dedupe_key', $keys)
            ->whereIn('status', [EmailLog::STATUS_DELIVERED, EmailLog::STATUS_FAILED])
            ->orderByDesc('id')
            ->get()
            ->unique('dedupe_key');

        if ($logs->isEmpty()) {
            return;
        }

        $logsByKey = $logs->keyBy('dedupe_key');
        $campaignIds = [];

        foreach ($rows as $row) {
            $log = $logsByKey->get(EmailCampaignRecipient::dedupeKey(
                (int) $row->email_campaign_id,
                (int) $row->user_id
            ));
            if (! $log) {
                continue;
            }

            $delivered = $log->status === EmailLog::STATUS_DELIVERED;
            // An older failed log must not kill a newer in-flight retry.
            if (! $delivered
                && $log->updated_at
                && $row->updated_at
                && ! $log->updated_at->greaterThan($row->updated_at)) {
                continue;
            }
            EmailCampaignRecipient::query()
                ->whereKey($row->id)
                ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                ->whereNull('email_log_id')
                ->update([
                    'status' => $delivered
                        ? EmailCampaignRecipient::STATUS_DELIVERED
                        : EmailCampaignRecipient::STATUS_FAILED,
                    'email_log_id' => (int) $log->id,
                    'skip_reason' => $delivered ? null : EmailCampaignRecipient::SKIP_ERROR,
                ]);

            $campaignIds[(int) $row->email_campaign_id] = true;
        }

        foreach (array_keys($campaignIds) as $id) {
            static::query()->find($id)?->recountRecipientTotals();
        }
    }

    /**
     * A timeout can claim pending → queued and die before Mail::send()
     * inserts the mailable. After the campaign stale window those orphans
     * are skipped so recount can go failed.
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
