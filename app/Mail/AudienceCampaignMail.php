<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\User;
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
        $result = parent::send($mailer);

        if ($result === null) {
            $this->markRecipientSkipped($this->skipReasonForSuppressedSend());
        }

        return $result;
    }

    public function failed(?\Throwable $exception): void
    {
        parent::failed($exception);
        $this->markRecipientFailed();
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

    protected function skipReasonForSuppressedSend(): string
    {
        if ($this->isStale()) {
            return EmailCampaignRecipient::SKIP_STALE;
        }

        if ($this->campaign->respect_preferences
            && ! EmailNotificationPreference::allows($this->recipient, 'marketing_emails')) {
            return EmailCampaignRecipient::SKIP_PREFERENCE;
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
        $logId = null;
        if (filled($this->dedupeKey)) {
            $logId = EmailLog::query()
                ->where('dedupe_key', $this->dedupeKey)
                ->where('status', EmailLog::STATUS_FAILED)
                ->latest('id')
                ->value('id');
        }

        $payload = [
            'status' => EmailCampaignRecipient::STATUS_FAILED,
            'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
        ];
        if ($logId) {
            $payload['email_log_id'] = (int) $logId;
        }

        $this->syncRecipientRow($payload);
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

            $updated = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaignId)
                ->where('user_id', $userId)
                ->whereIn('status', [
                    EmailCampaignRecipient::STATUS_PENDING,
                    EmailCampaignRecipient::STATUS_QUEUED,
                ])
                ->update($payload);

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
