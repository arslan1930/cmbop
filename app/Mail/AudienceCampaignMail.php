<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\User;
use App\Services\AudienceInventoryService;
use App\Support\EmailUnsubscribeLink;
use Carbon\Carbon;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AudienceCampaignMail extends PlatformMailable
{
    protected ?string $cachedUnsubscribeUrl = null;

    public function __construct(
        public EmailCampaign $campaign,
        public User $recipient,
    ) {
        parent::__construct();
        $this->notificationType = 'audience_campaign';
        $this->recipientUser = $recipient;
        $this->skipUserPreference = ! $campaign->respect_preferences;
        // Staff skip runs before parent::send() sets a default key.
        $this->dedupeKey = EmailCampaignRecipient::dedupeKey((int) $campaign->id, (int) $recipient->id);
    }

    public function unsubscribeUrl(): string
    {
        return $this->cachedUnsubscribeUrl ??= EmailUnsubscribeLink::url($this->recipient);
    }

    public function build()
    {
        return $this->subject($this->campaign->subject)
            ->markdown('emails.campaigns.audience')
            ->with([
                'firstName' => $this->firstName($this->recipient),
                'subject' => $this->campaign->subject,
                'bodyHtml' => $this->campaign->body_html,
                'ctaLabel' => $this->campaign->cta_label,
                'ctaUrl' => $this->campaign->cta_url,
                'brand' => $this->brand(),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ]);
    }

    public function headers(): Headers
    {
        $url = $this->unsubscribeUrl();

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$url.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    public function send($mailer)
    {
        if (AudienceInventoryService::userHasStaffRole($this->recipient)) {
            $this->suppressReason = 'staff';
            if (! filled($this->dedupeKey)) {
                [$campaignId, $userId] = $this->campaignAndUserIds();
                if ($campaignId > 0 && $userId > 0) {
                    $this->dedupeKey = EmailCampaignRecipient::dedupeKey($campaignId, $userId);
                }
            }
            // Staff skip runs before parent::send(), so abandonOpenLog
            // never ran — a retried pending Email Center row stayed
            // pending forever (retry only accepts failed).
            $this->abandonOpenLog($this->suppressErrorMessage());
            $this->markRecipientSkipped(EmailCampaignRecipient::SKIP_STAFF);

            return null;
        }

        if ($this->campaign->respect_preferences
            && ! EmailNotificationPreference::allows($this->recipient, $this->campaign->preferenceKey(), failClosed: true)) {
            $this->suppressReason = 'preference';
            $this->abandonOpenLog($this->suppressErrorMessage());
            $this->markRecipientSkipped(EmailCampaignRecipient::SKIP_PREFERENCE);

            return null;
        }

        $result = parent::send($mailer);

        if ($result !== null || $this->suppressReason === 'duplicate') {
            $this->markRecipientDelivered();
        } elseif ($result === null) {
            $this->markRecipientSkipped($this->skipReasonForSuppressedSend());
        }

        return $result;
    }

    public function failed(?\Throwable $exception): void
    {
        // A worker timeout after SMTP still runs failed(). MessageSent
        // already wrote delivered. Marking the recipient failed hid that
        // send from recover (it only heals queued rows), so the campaign
        // looked failed and a later compose blasted the audience again.
        if ($this->thisAttemptAlreadyDelivered()) {
            $this->suppressReason = 'duplicate';
            $this->abandonOpenLog($this->suppressErrorMessage());
            $this->markRecipientDelivered();

            return;
        }

        // Generic-key / recent sibling delivery still must not invent a
        // leftover failed Email Center log. alreadyHasDeliveredLog() now
        // ignores a leftover older than this job attempt.
        if ($this->alreadyHasDeliveredLog()) {
            $this->suppressReason = 'duplicate';
            $this->abandonOpenLog($this->suppressErrorMessage());
            $this->markRecipientDelivered();

            return;
        }

        parent::failed($exception);
        $this->markRecipientFailed();
    }

    /**
     * True when MessageSent already persisted a delivered log for this
     * job attempt (sent_at >= queuedAt). An older campaign delivery
     * must still record a later real failure.
     */
    protected function thisAttemptAlreadyDelivered(): bool
    {
        if (! filled($this->dedupeKey) || blank($this->queuedAt)) {
            return false;
        }

        try {
            if (! Schema::hasTable((new EmailLog)->getTable())) {
                return false;
            }

            $delivered = EmailLog::latestDeliveredByDedupe($this->dedupeKey);
            if (! $delivered?->sent_at) {
                return false;
            }

            return $delivered->sent_at->greaterThanOrEqualTo(
                Carbon::parse($this->queuedAt)->subSeconds(5)
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Campaigns may sit behind a backlog longer than transactional mail.
     */
    protected function isStale(): bool
    {
        $maxHours = (int) config('email_notifications.campaign_max_age_hours', 72);

        if ($maxHours <= 0 || blank($this->queuedAt)) {
            return false;
        }

        try {
            return Carbon::parse($this->queuedAt)->addHours($maxHours)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * parent::send() fills an empty key with type|email|class. That string
     * is not the one-shot campaign key, so isDuplicate() misses a real
     * delivery and Email Center leftover rows cannot find their sibling.
     */
    protected function defaultDedupeKey(?string $type, ?User $recipient): ?string
    {
        [$campaignId, $userId] = $this->campaignAndUserIds();
        if ($campaignId > 0 && $userId > 0) {
            return EmailCampaignRecipient::dedupeKey($campaignId, $userId);
        }

        return parent::defaultDedupeKey($type, $recipient);
    }

    protected function skipReasonForSuppressedSend(): string
    {
        if ($this->suppressReason === 'stale' || $this->isStale()) {
            return EmailCampaignRecipient::SKIP_STALE;
        }

        if ($this->suppressReason === 'preference'
            || ($this->campaign->respect_preferences
                && ! EmailNotificationPreference::allows($this->recipient, $this->campaign->preferenceKey(), failClosed: true))) {
            return EmailCampaignRecipient::SKIP_PREFERENCE;
        }

        if ($this->suppressReason === 'staff'
            || AudienceInventoryService::userHasStaffRole($this->recipient)) {
            return EmailCampaignRecipient::SKIP_STAFF;
        }

        return EmailCampaignRecipient::SKIP_DISABLED;
    }

    protected function markRecipientSkipped(string $reason): void
    {
        $this->syncRecipientRow([
            'status' => EmailCampaignRecipient::STATUS_SKIPPED,
            'skip_reason' => $reason,
        ]);
    }

    protected function markRecipientFailed(): void
    {
        $payload = [
            'status' => EmailCampaignRecipient::STATUS_FAILED,
            'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
        ];
        if ($logId = $this->latestLogIdForStatus(EmailLog::STATUS_FAILED)) {
            $payload['email_log_id'] = $logId;
        }

        $this->syncRecipientRow($payload);
    }

    protected function markRecipientDelivered(): void
    {
        $payload = [
            'status' => EmailCampaignRecipient::STATUS_DELIVERED,
            'skip_reason' => null,
        ];
        if ($logId = $this->latestLogIdForStatus(EmailLog::STATUS_DELIVERED)) {
            $payload['email_log_id'] = $logId;
        }

        $this->syncRecipientRow($payload);
    }

    /**
     * A historical send that wrote the generic default key must still
     * block a later job that uses `audience_campaign:{id}:user:{id}`.
     *
     * The generic string is per email, not per campaign. parent::isDuplicate()
     * is one-shot for any `audience_campaign` key, so campaign 2 would be
     * suppressed after campaign 1 mailed the same address under
     * `audience_campaign|{email}|AudienceCampaignMail`. Only campaign+user
     * identity is safe for that leftover shape.
     */
    protected function isDuplicate(string $key): bool
    {
        $sharedGenericKey = str_starts_with($key, 'audience_campaign|');
        if (! $sharedGenericKey && parent::isDuplicate($key)) {
            return true;
        }

        [$campaignId, $userId] = $this->campaignAndUserIds();
        if ($campaignId < 1 || $userId < 1) {
            return false;
        }

        try {
            if (! Schema::hasTable((new EmailLog)->getTable())) {
                return false;
            }

            // latestDeliveredForCampaignUser() swallows query errors as
            // "not delivered". Probe first so a locked table cannot look
            // like a first-time send and blast someone who already got it.
            EmailLog::query()->whereKey(0)->exists();

            $delivered = EmailLog::latestDeliveredForCampaignUser($campaignId, $userId);

            return $delivered !== null;
        } catch (\Throwable $e) {
            Log::warning('Campaign sibling dedupe check failed; holding send', [
                'campaign_id' => $campaignId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function latestLogIdForStatus(string $status): ?int
    {
        try {
            if (! Schema::hasTable((new EmailLog)->getTable())) {
                return null;
            }

            if ($status === EmailLog::STATUS_DELIVERED) {
                [$campaignId, $userId] = $this->campaignAndUserIds();
                $sibling = EmailLog::latestDeliveredForCampaignUser($campaignId, $userId);
                if ($sibling) {
                    return (int) $sibling->id;
                }
            }

            if (! filled($this->dedupeKey)) {
                return null;
            }

            $id = EmailLog::query()
                ->where('dedupe_key', $this->dedupeKey)
                ->where('status', $status)
                ->latest('id')
                ->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Failed / skipped sync must not clobber an expire-stale row (leave
     * stale). Delivered may overwrite stale, but not a preference /
     * disabled / unverified skip.
     *
     * @return list<string>
     */
    protected function syncableStatuses(): array
    {
        return [
            EmailCampaignRecipient::STATUS_PENDING,
            EmailCampaignRecipient::STATUS_QUEUED,
            EmailCampaignRecipient::STATUS_FAILED,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function syncRecipientRow(array $payload): void
    {
        [$campaignId, $userId] = $this->campaignAndUserIds();
        if ($campaignId < 1 || $userId < 1) {
            return;
        }

        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return;
            }

            $newStatus = (string) ($payload['status'] ?? '');
            $query = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaignId)
                ->where('user_id', $userId);

            if ($newStatus === EmailCampaignRecipient::STATUS_DELIVERED) {
                $query->openForDelivery();
            } else {
                $query->whereIn('status', $this->syncableStatuses());
            }

            $updated = $query->update($payload);

            if ($updated) {
                $campaign = EmailCampaign::query()->find($campaignId);
                $campaign?->recountRecipientTotals();
            }
        } catch (\Throwable $e) {
            Log::warning('Campaign recipient status sync failed', [
                'campaign_id' => $campaignId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function campaignAndUserIds(): array
    {
        try {
            $campaignId = (int) ($this->campaign->id ?? 0);
            $userId = (int) ($this->recipient->id ?? 0);
            if ($campaignId > 0 && $userId > 0) {
                return [$campaignId, $userId];
            }
        } catch (\Throwable) {
        }

        if (is_string($this->dedupeKey)
            && preg_match('/^audience_campaign:(\d+):user:(\d+)$/', $this->dedupeKey, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [0, 0];
    }
}
