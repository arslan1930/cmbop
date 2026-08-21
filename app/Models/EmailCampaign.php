<?php

namespace App\Models;

use App\Jobs\SendEmailCampaignJob;
use App\Mail\AudienceCampaignMail;
use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use App\Services\AudienceInventoryService;
use App\Support\MailJobPayload;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EmailCampaign extends Model
{
    use ToleratesMissingSchema, ToleratesUnparseableDates;

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
        'include_unverified',
        'created_by',
        'sent_at',
    ];

    protected $casts = [
        'selected_user_ids' => 'array',
        'respect_preferences' => 'boolean',
        'include_unverified' => 'boolean',
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

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditableDraft(): bool
    {
        if (! $this->isDraft()) {
            return false;
        }

        try {
            return ! $this->recipients()->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    public function canDuplicate(): bool
    {
        if ($this->isEditableDraft()) {
            return true;
        }

        return in_array($this->status, [self::STATUS_SENT, self::STATUS_FAILED], true);
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
        foreach (self::healQueuedRecipientsWithTerminalLog() as $id) {
            static::query()->find($id)?->recountRecipientTotals();
        }
        self::expireOrphanedQueuedRecipients();
        self::expireOrphanedPendingLogs();
        // Expire can fail a leftover pending log while the recipient still
        // points at that id. Heal again so this pass can sync delivered /
        // failed instead of leaving the row queued until the next recover.
        foreach (self::healQueuedRecipientsWithTerminalLog() as $id) {
            static::query()->find($id)?->recountRecipientTotals();
        }

        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

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
                })->orWhere(function ($failedLie) use ($stale) {
                    // Give-up / markFailed wipe leftover pending but leave
                    // queued claims. Those orphans were invisible to recover.
                    $failedLie->where('status', self::STATUS_FAILED)
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

            // Reconcile can flip this row to a terminal status before we
            // dispatch. A no-op SendEmailCampaignJob still burns the queue.
            if (in_array($campaign->status, [self::STATUS_SENT, self::STATUS_FAILED], true)
                && ! $campaign->hasInFlightRecipients()) {
                continue;
            }

            if ($campaign->status === self::STATUS_SENT && $campaign->hasInFlightRecipients()) {
                $campaign->finalizeIfIdle();
                $campaign->refresh();
                if ($campaign->status !== self::STATUS_SENDING) {
                    continue;
                }
            }

            if ($campaign->status === self::STATUS_FAILED && $campaign->hasInFlightRecipients()) {
                $hasPending = $campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                    ->exists();
                if (! $hasPending) {
                    $released = self::reclaimOrphanedQueuedRecipients($campaign);
                    if ($released === 0) {
                        $campaign->touch();

                        continue;
                    }
                }
                // Give-up stored the streak that parked this campaign.
                // Leaving it at MAX would send us back to failed before
                // the new send job can run (FAILED + leftover pending
                // after a crash between reclaim and this update).
                $campaign->clearFailStreak();
                $campaign->update([
                    'status' => self::STATUS_SENDING,
                    'sent_at' => null,
                ]);
                $campaign->refresh();
            }

            if ($campaign->status === self::STATUS_SENDING
                && ! $campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                    ->exists()) {
                if ($campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                    ->exists()) {
                    // In-flight mailables stay queued. A timeout can claim
                    // pending → queued and die before Mail::send() inserts
                    // the job — reclaim those orphans so a new send job
                    // can finish the audience instead of waiting 72h to skip.
                    $released = self::reclaimOrphanedQueuedRecipients($campaign);
                    if ($released === 0) {
                        $campaign->touch();

                        continue;
                    }

                    $campaign->clearFailStreak();
                } else {
                    $campaign->clearFailStreak();
                    $campaign->finalizeIfIdle();

                    continue;
                }
            }

            // failed() remembers MAX and dispatches the last attempt.
            // Give-up before that jobs-table check wiped leftover pending
            // beside a live SendEmailCampaignJob.
            if (self::hasQueuedSendJob((int) $id)) {
                $campaign->touch();

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
     * Reset queued rows that have no email log and no matching mailable
     * in the jobs table. A missing/unreadable jobs table, inline SMTP,
     * or Redis/SQS mail must not look empty — that would double-send
     * mail that is already in flight.
     *
     * Email Center retry pending-marks the log and leaves the recipient
     * queued. A missed jobs-table scan must not reclaim that row and
     * dispatch a send job beside the retried mailable. An unreadable
     * email_logs table must not look like “no pending retries”.
     */
    protected static function reclaimOrphanedQueuedRecipients(self $campaign): int
    {
        if (self::mailConnectionIsInline()) {
            // Inline SMTP never writes an AudienceCampaignMail jobs row.
            // A send-job timeout after pending → queued, during Mail::send(),
            // would look like an orphan and reclaim → double-send.
            // Expire at 72h is still the backstop (inFlight stays []).
            return 0;
        }

        $inFlight = self::inFlightCampaignMailUserIds((int) $campaign->id);
        if ($inFlight === null) {
            return 0;
        }

        $pendingHold = self::pendingLogUserIdsForCampaign((int) $campaign->id);
        if ($pendingHold === null) {
            return 0;
        }
        $deliveredIds = EmailLog::deliveredUserIdsForCampaign((int) $campaign->id);
        if ($deliveredIds === null) {
            return 0;
        }

        $holdUserIds = array_merge($inFlight, $pendingHold, $deliveredIds);

        $query = EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
            ->whereNull('email_log_id');

        $holdUserIds = array_values(array_unique(array_filter($holdUserIds)));
        if ($holdUserIds !== []) {
            $query->whereNotIn('user_id', $holdUserIds);
        }

        return $query->update([
            'status' => EmailCampaignRecipient::STATUS_PENDING,
            'skip_reason' => null,
        ]);
    }

    /**
     * Recipients with a pending Email Center row for this campaign.
     * Includes leftover generic-key retries that only store the pair in meta.
     * Null means the log table could not be read — callers must not treat
     * that as "no pending retries".
     *
     * Expire only holds logs newer than the campaign stale window — a
     * 72h leftover pending row is a lost retry and must still expire.
     *
     * @return list<int>|null
     */
    protected static function pendingLogUserIdsForCampaign(int $campaignId, ?\DateTimeInterface $fresherThan = null): ?array
    {
        if ($campaignId < 1) {
            return [];
        }

        $ids = [];
        $prefix = 'audience_campaign:'.$campaignId.':user:';

        try {
            foreach (EmailLog::query()
                ->where('status', EmailLog::STATUS_PENDING)
                ->where(function ($query) use ($prefix) {
                    $query->where('dedupe_key', 'like', $prefix.'%')
                        ->orWhere('notification_type', 'audience_campaign')
                        ->orWhere('template_key', 'audience_campaign')
                        ->orWhere('mailable', 'like', '%AudienceCampaignMail%')
                        ->orWhere('dedupe_key', 'like', 'audience_campaign|%');
                })
                ->get(['id', 'dedupe_key', 'meta', 'notification_type', 'template_key', 'mailable', 'updated_at']) as $log) {
                if ($fresherThan
                    && $log->updated_at
                    && ! $log->updated_at->greaterThan($fresherThan)) {
                    continue;
                }
                $userId = 0;
                $foundCampaign = (int) data_get($log->meta, 'campaign_id');
                $foundUser = (int) data_get($log->meta, 'user_id');
                if ($foundCampaign === $campaignId && $foundUser > 0) {
                    $userId = $foundUser;
                } elseif (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $log->dedupe_key, $matches)) {
                    $userId = (int) $matches[1];
                }
                if ($userId > 0) {
                    $ids[$userId] = true;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * User ids with an AudienceCampaignMail still sitting on a database
     * queue. Null means the scan failed and callers must not reclaim.
     *
     * Reclaim treats failed_jobs as in-flight so Email Center retry is
     * not doubled. Expire must not — a dead failed job is not a 72h
     * backlog, and counting it parked the recipient queued forever.
     *
     * @return list<int>|null
     */
    protected static function inFlightCampaignMailUserIds(int $campaignId, bool $includeFailedJobs = true): ?array
    {
        if ($campaignId < 1) {
            return [];
        }

        $ids = [];
        $sawUnscoped = false;
        $mailScannedOk = false;
        $prefix = 'audience_campaign:'.$campaignId.':user:';

        $mail = (string) config('email_notifications.queue_connection', config('queue.default'));
        $mailDriver = (string) config("queue.connections.{$mail}.driver");
        if ($mail !== '' && $mail !== 'sync' && $mailDriver !== 'sync' && $mailDriver !== '' && $mailDriver !== 'database') {
            // Mailables ride the mail connection. Redis/SQS there cannot
            // be inspected, so do not reclaim. An unused redis
            // queue.default must not block a healthy database mail queue.
            return null;
        }

        $mailNeedsScan = $mail !== '' && $mail !== 'sync' && $mailDriver === 'database';

        foreach (self::sendJobQueueConnections() as $connection) {
            try {
                $driver = (string) config("queue.connections.{$connection}.driver");
                if ($connection === 'sync' || $driver === 'sync' || $driver === '' || $driver !== 'database') {
                    continue;
                }

                $table = (string) config("queue.connections.{$connection}.table", 'jobs');
                if (! Schema::hasTable($table)) {
                    continue;
                }

                // Same trap as hasQueuedSendJob: SQLite can return an empty
                // set for a missing payload column. Aborting the whole scan
                // here parked orphans for 72h whenever the unused default
                // table was the broken one.
                if (! Schema::hasColumn($table, 'payload')) {
                    continue;
                }

                DB::table($table)
                    ->where(function ($query) use ($prefix) {
                        $query->where('payload', 'like', '%AudienceCampaignMail%')
                            ->orWhere('payload', 'like', '%'.$prefix.'%');
                    })
                    ->orderBy('id')
                    ->select(['id', 'payload'])
                    ->chunkById(100, function ($rows) use ($campaignId, &$ids, &$sawUnscoped) {
                        foreach ($rows as $row) {
                            $payload = (string) $row->payload;
                            if (! MailJobPayload::containsCampaignMail($payload, $campaignId)) {
                                continue;
                            }

                            $extracted = MailJobPayload::campaignMailUserIds($payload, $campaignId);
                            if ($extracted === []) {
                                $sawUnscoped = true;

                                return false;
                            }

                            foreach ($extracted as $userId) {
                                $ids[$userId] = true;
                            }
                        }

                        return true;
                    });

                if ($connection === $mail) {
                    $mailScannedOk = true;
                }
            } catch (\Throwable) {
                // A lock-timeout on the unused default table must not
                // discard a successful mail-queue scan.
            }
        }

        // A mailable that already failed is still retryable from Email
        // Center. Reclaiming that user would dispatch a second send.
        if ($includeFailedJobs) {
            try {
                $failedTable = (string) config('queue.failed.table', 'failed_jobs');
                if (Schema::hasTable($failedTable)) {
                    if (! Schema::hasColumn($failedTable, 'payload')) {
                        return null;
                    }

                    self::collectCampaignMailUserIdsFromTable(
                        $failedTable,
                        $campaignId,
                        $prefix,
                        $ids,
                        $sawUnscoped
                    );
                }
            } catch (\Throwable) {
                return null;
            }
        }

        if ($sawUnscoped) {
            return null;
        }

        // Mailables live on the mail connection. If that table could not
        // be read, fail-closed — the unused default being healthy/empty
        // must not look like "nothing in flight".
        if ($mailNeedsScan && ! $mailScannedOk) {
            return null;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @param  array<int, true>  $ids
     */
    protected static function collectCampaignMailUserIdsFromTable(
        string $table,
        int $campaignId,
        string $prefix,
        array &$ids,
        bool &$sawUnscoped
    ): void {
        DB::table($table)
            ->where(function ($query) use ($prefix) {
                $query->where('payload', 'like', '%AudienceCampaignMail%')
                    ->orWhere('payload', 'like', '%'.$prefix.'%');
            })
            ->orderBy('id')
            ->select(['id', 'payload'])
            ->chunkById(100, function ($rows) use ($campaignId, &$ids, &$sawUnscoped) {
                foreach ($rows as $row) {
                    $payload = (string) $row->payload;
                    if (! MailJobPayload::containsCampaignMail($payload, $campaignId)) {
                        continue;
                    }

                    $extracted = MailJobPayload::campaignMailUserIds($payload, $campaignId);
                    if ($extracted === []) {
                        $sawUnscoped = true;

                        return false;
                    }

                    foreach ($extracted as $userId) {
                        $ids[$userId] = true;
                    }
                }

                return true;
            });
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

        $scanFailed = false;
        $scannedOk = false;

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

                // SQLite treats a missing "payload" identifier as the string
                // literal 'payload' (DQS), so the LIKE scan returns empty
                // instead of throwing. MySQL would error. Either way this
                // is not "no job" — recover must not enqueue another send.
                if (! Schema::hasColumn($table, 'payload')) {
                    $scanFailed = true;

                    continue;
                }

                $found = false;
                DB::table($table)
                    ->where('payload', 'like', '%SendEmailCampaignJob%')
                    ->orderBy('id')
                    ->select(['id', 'payload'])
                    ->chunkById(100, function ($rows) use ($campaignId, &$found) {
                        $found = $rows->contains(fn ($row) => MailJobPayload::containsSendCampaignJob(
                            (string) $row->payload,
                            $campaignId
                        ));

                        return ! $found;
                    });

                $scannedOk = true;

                if ($found) {
                    return true;
                }
            } catch (\Throwable) {
                // A lock-timeout or missing payload column must not look
                // like "no job" — recover would enqueue another send.
                $scanFailed = true;
            }
        }

        // Only fail-closed when every database queue we could read failed.
        // A healthy empty jobs table must still redispatch, even if the
        // unused connection is broken — otherwise pending rows sit forever.
        return $scanFailed && ! $scannedOk;
    }

    /**
     * True when campaign mail is delivered inline (no jobs-table mailable).
     * Reclaim must not treat that empty scan as "nothing in flight".
     */
    protected static function mailConnectionIsInline(): bool
    {
        $mail = (string) config('email_notifications.queue_connection', config('queue.default'));
        $driver = (string) config("queue.connections.{$mail}.driver");

        return $mail === '' || $mail === 'sync' || $driver === '' || $driver === 'sync';
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
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $cutoff = now()->subMinutes(max(1, $staleMinutes));
        $attachedIds = self::healQueuedRecipientsWithTerminalLog();
        // Delivered logs are attached even when the queued row is younger
        // than the stall window. Waiting let reclaim dispatch a second send
        // beside a MessageSent that had already written email_logs.
        $rows = EmailCampaignRecipient::query()
            ->whereNull('email_log_id')
            ->where(function ($query) {
                $query->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                    ->orWhere(function ($skipped) {
                        $skipped->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                            ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE);
                    });
            })
            ->get(['id', 'email_campaign_id', 'user_id', 'status', 'updated_at']);

        if ($rows->isEmpty()) {
            foreach ($attachedIds as $id) {
                static::query()->find($id)?->recountRecipientTotals();
            }

            return;
        }

        $keys = $rows->map(fn (EmailCampaignRecipient $row) => EmailCampaignRecipient::dedupeKey(
            (int) $row->email_campaign_id,
            (int) $row->user_id
        ))->unique()->values()->all();
        $neededPairs = [];
        foreach ($rows as $row) {
            $neededPairs[(int) $row->email_campaign_id.':'.(int) $row->user_id] = true;
        }

        try {
            $logs = EmailLog::query()
                ->whereIn('dedupe_key', $keys)
                ->whereIn('status', [
                    EmailLog::STATUS_PENDING,
                    EmailLog::STATUS_DELIVERED,
                    EmailLog::STATUS_FAILED,
                ])
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable) {
            return;
        }

        try {
            $campaignIds = $rows->pluck('email_campaign_id')->unique()->filter()
                ->map(fn ($id) => (int) $id)->values()->all();
            if ($campaignIds !== []) {
                $extras = EmailLog::query()
                    ->whereIn('status', [
                        EmailLog::STATUS_PENDING,
                        EmailLog::STATUS_DELIVERED,
                        EmailLog::STATUS_FAILED,
                    ])
                    ->where(function ($query) use ($campaignIds) {
                        foreach ($campaignIds as $campaignId) {
                            $query->orWhere(function ($one) use ($campaignId) {
                                $one->where('meta->campaign_id', $campaignId)
                                    ->orWhere('dedupe_key', 'like', 'audience_campaign:'.$campaignId.':user:%');
                            });
                        }
                    })
                    ->orderByDesc('id')
                    ->get();
                $logs = $logs->concat($extras)->unique('id')->values();
            }
        } catch (\Throwable) {
            // Canonical-key rows still attach when JSON meta cannot be queried.
        }

        $logs = $logs
            ->filter(function (EmailLog $log) use ($neededPairs, $keys) {
                [$campaignId, $userId] = EmailLog::campaignUserIds($log);
                if ($campaignId > 0 && $userId > 0) {
                    return isset($neededPairs[$campaignId.':'.$userId]);
                }

                return in_array((string) $log->dedupe_key, $keys, true);
            })
            ->groupBy(function (EmailLog $log) {
                [$campaignId, $userId] = EmailLog::campaignUserIds($log);
                if ($campaignId > 0 && $userId > 0) {
                    return EmailCampaignRecipient::dedupeKey($campaignId, $userId);
                }

                return (string) $log->dedupe_key;
            });

        $campaignIds = array_fill_keys($attachedIds, true);

        // Walk every queued row even when grouping found nothing.
        // latestDeliveredForCampaignUser() still attaches a leftover
        // generic-key delivery after a failed extras JSON scan.
        foreach ($rows as $row) {
            try {
                self::reconcileOneQueuedRecipientFromLogs($row, $logs, $cutoff, $campaignIds);
            } catch (\Throwable $e) {
                Log::warning('Campaign recipient reconcile skipped a leftover row', [
                    'recipient_id' => $row->id ?? null,
                    'campaign_id' => $row->email_campaign_id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (array_keys($campaignIds) as $id) {
            static::query()->find($id)?->recountRecipientTotals();
        }
    }

    /**
     * @param  Collection<string, mixed>  $logs
     * @param  array<int, true>  $campaignIds
     */
    protected static function reconcileOneQueuedRecipientFromLogs(
        EmailCampaignRecipient $row,
        $logs,
        Carbon $cutoff,
        array &$campaignIds,
    ): void {
        $group = $logs->get(EmailCampaignRecipient::dedupeKey(
            (int) $row->email_campaign_id,
            (int) $row->user_id
        ));

        $deliveredLog = $group?->first(
            fn (EmailLog $log) => $log->status === EmailLog::STATUS_DELIVERED
        );
        // Exact-key / extras grouping misses a leftover generic
        // audience_campaign|{email}|AudienceCampaignMail row when the
        // JSON meta scan fails. Heal already falls back; reclaim and
        // expire then hold that user forever and never attach.
        if (! $deliveredLog) {
            $deliveredLog = EmailLog::latestDeliveredForCampaignUser(
                (int) $row->email_campaign_id,
                (int) $row->user_id
            );
        }
        // A pending log means a retry may be in flight — do not attach
        // a failed log over that. A delivered log still wins: expire
        // would otherwise skip-stale someone who already received the
        // mail, and a later retry doubles the send.
        if (! $deliveredLog && ! $group) {
            return;
        }
        if (! $deliveredLog && $group->contains(fn (EmailLog $log) => $log->status === EmailLog::STATUS_PENDING)) {
            return;
        }
        $failedLog = $group?->first(
            fn (EmailLog $log) => $log->status === EmailLog::STATUS_FAILED
        );
        $pendingLogs = $group
            ? $group->filter(fn (EmailLog $log) => $log->status === EmailLog::STATUS_PENDING)
            : collect();

        // A leftover pending row must not block a real delivery. Only a
        // newer pending (retry after that delivery) stays in-flight.
        if ($pendingLogs->isNotEmpty()) {
            $freshPending = $pendingLogs->first(function (EmailLog $pending) use ($deliveredLog) {
                return ! $deliveredLog
                    || ($pending->updated_at
                        && $deliveredLog->updated_at
                        && $pending->updated_at->greaterThan($deliveredLog->updated_at));
            });
            if ($freshPending) {
                return;
            }
        }

        $staleSkip = $row->status === EmailCampaignRecipient::STATUS_SKIPPED;
        // Expire already parked the row. Only a delivered log proves the
        // mail went out — do not revive a stale skip from an old failure.
        if ($staleSkip && ! $deliveredLog) {
            return;
        }
        // Leftover garbage timestamps used to throw here and abort the
        // rest of recover. Unreadable clocks also must not attach a
        // failed log — that would kill an in-flight retry we cannot
        // prove is stale. Delivered still attaches at any age.
        if (! $deliveredLog && ! $row->updated_at) {
            return;
        }
        if (! $deliveredLog
            && $row->updated_at
            && $row->updated_at->greaterThan($cutoff)) {
            return;
        }

        $log = $deliveredLog;
        if (! $log && $failedLog) {
            // An older failed log must not kill a newer in-flight retry.
            if ($failedLog->updated_at
                && $row->updated_at
                && ! $failedLog->updated_at->greaterThan($row->updated_at)) {
                return;
            }
            $log = $failedLog;
        }

        if (! $log) {
            return;
        }

        $delivered = $log->status === EmailLog::STATUS_DELIVERED;
        // An older failed log must not kill a newer in-flight retry.
        if (! $delivered
            && $log->updated_at
            && $row->updated_at
            && ! $log->updated_at->greaterThan($row->updated_at)) {
            return;
        }
        $expected = $staleSkip
            ? EmailCampaignRecipient::STATUS_SKIPPED
            : EmailCampaignRecipient::STATUS_QUEUED;

        EmailCampaignRecipient::query()
            ->whereKey($row->id)
            ->where('status', $expected)
            ->whereNull('email_log_id')
            ->update([
                'status' => $delivered
                    ? EmailCampaignRecipient::STATUS_DELIVERED
                    : EmailCampaignRecipient::STATUS_FAILED,
                'email_log_id' => (int) $log->id,
                'skip_reason' => $delivered ? null : EmailCampaignRecipient::SKIP_ERROR,
            ]);

        // Heal only sees rows that already have an email_log_id. After this
        // attach the recipient is delivered, so leftover pending siblings
        // would stay open and look in-flight to a later compose.
        if ($delivered && $pendingLogs->isNotEmpty()) {
            foreach ($pendingLogs as $pending) {
                EmailLog::query()
                    ->whereKey($pending->id)
                    ->where('status', EmailLog::STATUS_PENDING)
                    ->update([
                        'status' => EmailLog::STATUS_FAILED,
                        'error' => 'Closed: duplicate open log for the same send',
                    ]);
            }
        }

        $campaignIds[(int) $row->email_campaign_id] = true;
    }

    /**
     * Queued + a terminal log FK is not in-flight. Expire used to skip
     * those rows forever, so a failed campaign could keep a queued leftover.
     *
     * Look up by audience_campaign:{id}:user:{id}, not only the attached
     * id. SMTP can persist a delivered row and leave the recipient pointing
     * at a leftover pending/failed log. Trusting that FK marked a real
     * send failed, recover finalized the campaign failed, and a later
     * compose doubled the audience.
     *
     * @return list<int>
     */
    protected static function healQueuedRecipientsWithTerminalLog(): array
    {
        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $rows = EmailCampaignRecipient::query()
            ->whereNotNull('email_log_id')
            ->where(function ($query) {
                $query->whereIn('status', [
                    EmailCampaignRecipient::STATUS_QUEUED,
                    EmailCampaignRecipient::STATUS_FAILED,
                ])->orWhere(function ($skipped) {
                    $skipped->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                        ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE);
                });
            })
            ->get(['id', 'email_campaign_id', 'user_id', 'status', 'email_log_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $logIds = $rows->pluck('email_log_id')->unique()->filter()->all();
        $keys = $rows->map(fn (EmailCampaignRecipient $row) => EmailCampaignRecipient::dedupeKey(
            (int) $row->email_campaign_id,
            (int) $row->user_id
        ))->unique()->values()->all();

        try {
            $allLogs = EmailLog::query()
                ->where(function ($query) use ($logIds, $keys) {
                    $query->whereIn('id', $logIds);
                    if ($keys !== []) {
                        $query->orWhere(function ($byKey) use ($keys) {
                            $byKey->whereIn('dedupe_key', $keys)
                                ->whereIn('status', [
                                    EmailLog::STATUS_PENDING,
                                    EmailLog::STATUS_DELIVERED,
                                    EmailLog::STATUS_FAILED,
                                ]);
                        });
                    }
                })
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        try {
            $campaignIds = $rows->pluck('email_campaign_id')->unique()->filter()
                ->map(fn ($id) => (int) $id)->values()->all();
            if ($campaignIds !== []) {
                $extras = EmailLog::query()
                    ->whereIn('status', [
                        EmailLog::STATUS_PENDING,
                        EmailLog::STATUS_DELIVERED,
                        EmailLog::STATUS_FAILED,
                    ])
                    ->where(function ($query) use ($campaignIds) {
                        foreach ($campaignIds as $campaignId) {
                            $query->orWhere(function ($one) use ($campaignId) {
                                $one->where('meta->campaign_id', $campaignId)
                                    ->orWhere('dedupe_key', 'like', 'audience_campaign:'.$campaignId.':user:%');
                            });
                        }
                    })
                    ->orderByDesc('id')
                    ->get();
                $allLogs = $allLogs->concat($extras)->unique('id')->values();
            }
        } catch (\Throwable) {
            // Canonical-key rows still heal when JSON meta cannot be queried.
        }

        $logsById = $allLogs->keyBy('id');
        $logsByKey = $allLogs
            ->filter(fn (EmailLog $log) => filled($log->dedupe_key) || EmailLog::campaignUserIds($log) !== [0, 0])
            ->groupBy(function (EmailLog $log) {
                [$campaignId, $userId] = EmailLog::campaignUserIds($log);
                if ($campaignId > 0 && $userId > 0) {
                    return EmailCampaignRecipient::dedupeKey($campaignId, $userId);
                }

                return (string) $log->dedupe_key;
            });

        $healedCampaigns = [];

        foreach ($rows as $row) {
            $attached = $logsById->get((int) $row->email_log_id);
            $group = $logsByKey->get(EmailCampaignRecipient::dedupeKey(
                (int) $row->email_campaign_id,
                (int) $row->user_id
            ));

            $deliveredLog = $group?->first(
                fn (EmailLog $log) => $log->status === EmailLog::STATUS_DELIVERED
            );
            if (! $deliveredLog && $attached?->status === EmailLog::STATUS_DELIVERED) {
                $deliveredLog = $attached;
            }
            // Exact-key grouping misses a leftover generic
            // audience_campaign|{email}|AudienceCampaignMail row. Trusting
            // only the attached failed/pending FK then marked a real send
            // failed and a later compose doubled the audience.
            if (! $deliveredLog) {
                $deliveredLog = EmailLog::latestDeliveredForCampaignUser(
                    (int) $row->email_campaign_id,
                    (int) $row->user_id
                );
            }

            $pendingLogs = $group
                ? $group->filter(fn (EmailLog $log) => $log->status === EmailLog::STATUS_PENDING)
                : collect();
            if ($attached?->status === EmailLog::STATUS_PENDING
                && ! $pendingLogs->contains(fn (EmailLog $log) => (int) $log->id === (int) $attached->id)) {
                $pendingLogs = $pendingLogs->push($attached);
            }

            $failedLog = $group?->first(
                fn (EmailLog $log) => $log->status === EmailLog::STATUS_FAILED
            );
            if (! $failedLog && $attached?->status === EmailLog::STATUS_FAILED) {
                $failedLog = $attached;
            }

            if ($pendingLogs->isNotEmpty()) {
                $freshPending = $pendingLogs->first(function (EmailLog $pending) use ($deliveredLog) {
                    return ! $deliveredLog
                        || ($pending->updated_at
                            && $deliveredLog->updated_at
                            && $pending->updated_at->greaterThan($deliveredLog->updated_at));
                });
                if ($freshPending) {
                    continue;
                }

                foreach ($pendingLogs as $pending) {
                    EmailLog::query()
                        ->whereKey($pending->id)
                        ->where('status', EmailLog::STATUS_PENDING)
                        ->update([
                            'status' => EmailLog::STATUS_FAILED,
                            'error' => 'Closed: duplicate open log for the same send',
                        ]);
                }
            }

            $staleSkip = $row->status === EmailCampaignRecipient::STATUS_SKIPPED;
            if ($staleSkip && ! $deliveredLog) {
                continue;
            }
            if ($row->status === EmailCampaignRecipient::STATUS_FAILED && ! $deliveredLog) {
                continue;
            }

            $log = $deliveredLog;
            if (! $log && $row->status === EmailCampaignRecipient::STATUS_QUEUED) {
                $log = $failedLog;
            }
            if (! $log) {
                continue;
            }

            $delivered = $log->status === EmailLog::STATUS_DELIVERED;
            $query = EmailCampaignRecipient::query()
                ->whereKey($row->id)
                ->where('email_log_id', (int) $row->email_log_id);

            if ($staleSkip) {
                $query->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                    ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE);
            } else {
                $query->where('status', $row->status);
            }

            $query->update([
                'status' => $delivered
                    ? EmailCampaignRecipient::STATUS_DELIVERED
                    : EmailCampaignRecipient::STATUS_FAILED,
                'email_log_id' => (int) $log->id,
                'skip_reason' => $delivered ? null : EmailCampaignRecipient::SKIP_ERROR,
            ]);

            $healedCampaigns[(int) $row->email_campaign_id] = true;
        }

        return array_keys($healedCampaigns);
    }

    /**
     * A timeout can claim pending → queued and die before Mail::send()
     * inserts the mailable. After the campaign stale window those orphans
     * are skipped so recount can go failed. A still-queued mailable is
     * not an orphan — skip it and a later retry doubles the send.
     * A failed_jobs row is already dead; expire that 72h leftover.
     */
    protected static function expireOrphanedQueuedRecipients(): void
    {
        $hours = (int) config('email_notifications.campaign_max_age_hours', 72);
        if ($hours <= 0 || ! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
            return;
        }

        $cutoff = now()->subHours($hours);
        $expired = EmailCampaignRecipient::query()
            ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
            ->whereNull('email_log_id')
            ->where('updated_at', '<=', $cutoff)
            ->get(['id', 'email_campaign_id', 'user_id']);

        $expireIds = [];
        $campaignIds = [];

        foreach ($expired->groupBy('email_campaign_id') as $campaignId => $group) {
            $campaignId = (int) $campaignId;
            $inFlight = self::inFlightCampaignMailUserIds($campaignId, includeFailedJobs: false);
            // Unreadable mail queue: still expire (72h backstop). A
            // readable queue with a live AudienceCampaignMail must not
            // park that user as skipped-stale — expire + retry then
            // queued a second job beside the backlogged one.
            // failed_jobs is not a live backlog: reclaim still holds
            // those users, but expire must close the 72h orphan.
            // A pending Email Center log is a just-retried mailable.
            // Reclaim already holds those users; expire used to skip-stale
            // them and fail the pending row when the jobs-table scan
            // missed the retried job — a second retry doubled the send.
            $pendingHold = self::pendingLogUserIdsForCampaign($campaignId, $cutoff);
            if ($pendingHold === null) {
                continue;
            }
            $deliveredHold = EmailLog::deliveredUserIdsForCampaign($campaignId);
            if ($deliveredHold === null) {
                continue;
            }
            $blocked = $inFlight === null ? [] : array_fill_keys($inFlight, true);
            foreach ($pendingHold as $userId) {
                $blocked[$userId] = true;
            }
            foreach ($deliveredHold as $userId) {
                $blocked[$userId] = true;
            }

            foreach ($group as $row) {
                if (isset($blocked[(int) $row->user_id])) {
                    continue;
                }

                $expireIds[] = (int) $row->id;
                if ($campaignId > 0) {
                    $campaignIds[$campaignId] = true;
                }
            }
        }

        if ($expireIds !== []) {
            EmailCampaignRecipient::query()
                ->whereIn('id', $expireIds)
                ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                ->whereNull('email_log_id')
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_SKIPPED,
                    'skip_reason' => EmailCampaignRecipient::SKIP_STALE,
                ]);

            foreach (array_keys($campaignIds) as $id) {
                static::query()->find($id)?->recountRecipientTotals();
            }
        }

        self::failPendingLogsForStaleRecipients();
    }

    /**
     * Retry pending-marks the Email Center row and clears the recipient FK.
     * If that mailable is then lost, expire skipped the recipient but the
     * log stayed pending — retry only works on failed logs.
     *
     * Do not Schema::hasTable('email_logs') here: recoverStalled() runs on
     * Email Center page views, and that probe is counted as an email_logs
     * query. Skip the table entirely when there are no stale-skip keys.
     *
     * Do not close a pending log while that user's mailable is still on
     * the queue. Expire can skip a 72h queued row whose job is only
     * backlogged, and a blind close made the log look failed again —
     * a second retry then doubles the send. Redis/SQS mail (unreadable)
     * must leave those pending logs alone.
     */
    protected static function failPendingLogsForStaleRecipients(): void
    {
        try {
            $rows = EmailCampaignRecipient::query()
                ->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE)
                ->get(['email_campaign_id', 'user_id']);
        } catch (\Throwable) {
            return;
        }

        if ($rows->isEmpty()) {
            return;
        }

        $pairs = [];

        foreach ($rows->groupBy('email_campaign_id') as $campaignId => $group) {
            $campaignId = (int) $campaignId;
            $inFlight = self::inFlightCampaignMailUserIds($campaignId, includeFailedJobs: false);
            if ($inFlight === null) {
                continue;
            }

            $blocked = array_fill_keys($inFlight, true);
            foreach ($group as $row) {
                $userId = (int) $row->user_id;
                if (isset($blocked[$userId])) {
                    continue;
                }

                $pairs[$campaignId.':'.$userId] = true;
            }
        }

        if ($pairs === []) {
            return;
        }

        $now = now();

        try {
            foreach (EmailLog::query()
                ->where('status', EmailLog::STATUS_PENDING)
                ->where(function ($query) {
                    $query->where('notification_type', 'audience_campaign')
                        ->orWhere('template_key', 'audience_campaign')
                        ->orWhere('mailable', 'like', '%AudienceCampaignMail%')
                        ->orWhere('dedupe_key', 'like', 'audience_campaign%');
                })
                ->get(['id', 'dedupe_key', 'meta', 'notification_type', 'template_key', 'mailable']) as $log) {
                [$campaignId, $userId] = EmailLog::campaignUserIds($log);
                if ($campaignId < 1 || $userId < 1 || ! isset($pairs[$campaignId.':'.$userId])) {
                    continue;
                }

                EmailLog::query()
                    ->whereKey($log->id)
                    ->where('status', EmailLog::STATUS_PENDING)
                    ->update([
                        'status' => EmailLog::STATUS_FAILED,
                        'error' => 'Expired: campaign mail was not confirmed',
                        'updated_at' => $now,
                    ]);
            }
        } catch (\Throwable) {
            // Missing email_logs table must not break recover.
        }
    }

    /**
     * Email Center retry pending-marks a log. If that job then vanishes
     * (deleted jobs row, botched deploy), transactional mail has no
     * campaign recipient for expire to close — the row stays pending and
     * retry only accepts failed. Fail old pending logs that are not still
     * sitting on a database queue.
     */
    protected static function expireOrphanedPendingLogs(): void
    {
        $transactionalHours = (int) config('email_notifications.max_age_hours', 24);
        $campaignHours = (int) config('email_notifications.campaign_max_age_hours', 72);
        if ($transactionalHours <= 0 && $campaignHours <= 0) {
            return;
        }

        $transactionalCutoff = $transactionalHours > 0
            ? now()->subHours($transactionalHours)
            : null;
        $campaignCutoff = $campaignHours > 0
            ? now()->subHours($campaignHours)
            : null;
        $fetchCutoff = $transactionalCutoff && $campaignCutoff
            ? ($transactionalCutoff->greaterThan($campaignCutoff) ? $transactionalCutoff : $campaignCutoff)
            : ($transactionalCutoff ?? $campaignCutoff);
        if (! $fetchCutoff) {
            return;
        }

        try {
            $pending = EmailLog::query()
                ->where('status', EmailLog::STATUS_PENDING)
                ->where('updated_at', '<=', $fetchCutoff)
                ->get();
        } catch (\Throwable) {
            return;
        }

        if ($pending->isEmpty()) {
            return;
        }

        $payloads = self::queuedMailablePayloads();
        if ($payloads === null) {
            return;
        }

        $now = now();

        foreach ($pending as $log) {
            if (self::isCampaignEmailLog($log)) {
                if (! $campaignCutoff || ($log->updated_at && $log->updated_at->greaterThan($campaignCutoff))) {
                    continue;
                }
            } elseif (! $transactionalCutoff) {
                continue;
            }

            $inFlight = false;
            foreach ($payloads as $payload) {
                if (MailJobPayload::matchesEmailLog($payload, $log, requireToken: true)) {
                    $inFlight = true;
                    break;
                }

                // Unidentified SendQueuedMailable (class only). requireToken
                // cannot prove this is a different recipient — closing the
                // log lets retry fire beside the live job.
                if (! MailJobPayload::looksIdentified($payload)
                    && self::unidentifiedPayloadCouldBeLog($payload, $log)) {
                    $inFlight = true;
                    break;
                }
            }
            if ($inFlight) {
                continue;
            }

            try {
                EmailLog::query()
                    ->whereKey($log->id)
                    ->where('status', EmailLog::STATUS_PENDING)
                    ->update([
                        'status' => EmailLog::STATUS_FAILED,
                        'error' => 'Expired: mail job was not confirmed',
                        'updated_at' => $now,
                    ]);
            } catch (\Throwable) {
            }
        }
    }

    protected static function isCampaignEmailLog(EmailLog $log): bool
    {
        if (str_starts_with((string) $log->template_key, 'audience_campaign')
            || (string) $log->notification_type === 'audience_campaign') {
            return true;
        }

        $mailable = (string) $log->mailable;
        if ($mailable === AudienceCampaignMail::class
            || str_contains($mailable, 'AudienceCampaignMail')) {
            return true;
        }

        $key = (string) $log->dedupe_key;

        return str_starts_with($key, 'audience_campaign:')
            || str_starts_with($key, 'audience_campaign|');
    }

    /**
     * SendQueuedMailable payloads still on a database queue. Null means the
     * mail connection could not be read — callers must not expire pending
     * logs that might still be in flight.
     *
     * Same unused-table trap as hasQueuedSendJob / inFlight: a missing
     * payload column or lock-timeout on queue.default must not abort a
     * healthy mail-queue scan, or lost pending logs stay pending forever.
     *
     * @return list<string>|null
     */
    protected static function queuedMailablePayloads(): ?array
    {
        $mail = (string) config('email_notifications.queue_connection', config('queue.default'));
        $mailDriver = (string) config("queue.connections.{$mail}.driver");
        if ($mail !== '' && $mail !== 'sync' && $mailDriver !== 'sync' && $mailDriver !== '' && $mailDriver !== 'database') {
            return null;
        }

        $payloads = [];
        $mailScannedOk = false;
        $mailNeedsScan = $mail !== '' && $mail !== 'sync' && $mailDriver === 'database';

        foreach (self::sendJobQueueConnections() as $connection) {
            try {
                $driver = (string) config("queue.connections.{$connection}.driver");
                if ($connection === 'sync' || $driver === 'sync' || $driver === '' || $driver !== 'database') {
                    continue;
                }

                $table = (string) config("queue.connections.{$connection}.table", 'jobs');
                if (! Schema::hasTable($table)) {
                    continue;
                }

                // Same trap as reclaim: a second database table without
                // payload (or a lock-timeout on the unused default) must
                // not look like “mail still in flight”. That parked every
                // lost Welcome/order pending log so retry could never run.
                if (! Schema::hasColumn($table, 'payload')) {
                    continue;
                }

                DB::table($table)
                    ->orderBy('id')
                    ->select(['id', 'payload'])
                    ->chunkById(100, function ($rows) use (&$payloads) {
                        foreach ($rows as $row) {
                            $payload = (string) $row->payload;
                            if (MailJobPayload::isQueuedMailable($payload)) {
                                $payloads[] = $payload;
                            }
                        }
                    });

                if ($connection === $mail) {
                    $mailScannedOk = true;
                }
            } catch (\Throwable) {
                // Unused default lock-timeout must not discard a successful
                // mail-queue scan.
            }
        }

        if ($mailNeedsScan && ! $mailScannedOk) {
            return null;
        }

        return $payloads;
    }

    protected static function unidentifiedPayloadCouldBeLog(string $payload, EmailLog $log): bool
    {
        $class = (string) $log->mailable;
        if ($class !== '') {
            return MailJobPayload::containsMailable($payload, $class);
        }

        return MailJobPayload::isQueuedMailable($payload);
    }
}
