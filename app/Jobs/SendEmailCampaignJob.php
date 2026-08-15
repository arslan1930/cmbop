<?php

namespace App\Jobs;

use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_SIZE = 20;

    public const MAX_FAIL_STREAK = 2;

    public int $tries = 1;

    /**
     * One batch must finish inside the web-drain worker timeout (30s) and
     * mail:drain-queue max-time. A 600s monolith was killed mid-send and
     * markFailed() then wiped every leftover pending row.
     */
    public int $timeout = 25;

    public function __construct(public int $campaignId, public int $failStreak = 0)
    {
        $this->onQueue(config('email_notifications.queue', 'emails'));
    }

    public function handle(): void
    {
        $campaign = EmailCampaign::query()->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        if ($campaign->status === EmailCampaign::STATUS_QUEUED) {
            $claimed = EmailCampaign::query()
                ->whereKey($this->campaignId)
                ->where('status', EmailCampaign::STATUS_QUEUED)
                ->update(['status' => EmailCampaign::STATUS_SENDING]);

            if ($claimed === 0) {
                $campaign->refresh();
                if ($campaign->status !== EmailCampaign::STATUS_SENDING) {
                    return;
                }
            } else {
                $campaign->refresh();
            }
        } elseif ($campaign->status !== EmailCampaign::STATUS_SENDING) {
            return;
        }

        $campaign->touch();

        try {
            $more = $this->processPending($campaign);
            if ($more) {
                $campaign->clearFailStreak();
                SendEmailCampaignJob::dispatch($this->campaignId);

                return;
            }
            $campaign->clearFailStreak();
            $this->finalize($campaign);
        } catch (\Throwable $e) {
            Log::error('Campaign job failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            $this->continueOrGiveUp($campaign);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $campaign = EmailCampaign::query()->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $this->continueOrGiveUp($campaign);
    }

    protected function processPending(EmailCampaign $campaign): bool
    {
        if (! EmailNotificationSetting::isEnabled('audience_campaign')) {
            EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaign->id)
                ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_SKIPPED,
                    'skip_reason' => EmailCampaignRecipient::SKIP_DISABLED,
                ]);

            return false;
        }

        $rows = EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->with('user')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($rows as $row) {
            $this->deliverOne($campaign, $row);
        }

        return $this->hasPending($campaign);
    }

    protected function deliverOne(EmailCampaign $campaign, EmailCampaignRecipient $row): void
    {
        $user = $row->user;
        if (! $user) {
            $this->claimPending($row, EmailCampaignRecipient::STATUS_FAILED, EmailCampaignRecipient::SKIP_ERROR);

            return;
        }

        if ($campaign->respect_preferences && ! EmailNotificationPreference::allows($user, 'marketing_emails')) {
            $this->claimPending($row, EmailCampaignRecipient::STATUS_SKIPPED, EmailCampaignRecipient::SKIP_PREFERENCE);

            return;
        }

        $claimed = EmailCampaignRecipient::query()
            ->whereKey($row->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->update(['status' => EmailCampaignRecipient::STATUS_QUEUED]);

        if ($claimed === 0) {
            return;
        }

        try {
            $mailable = new AudienceCampaignMail($campaign, $user);
            $mailable->notificationType = 'audience_campaign';
            $mailable->dedupeKey = EmailCampaignRecipient::dedupeKey((int) $campaign->id, (int) $user->id);

            Mail::to($user->email)->send($mailable);
        } catch (\Throwable $e) {
            $row->refresh();
            if (! in_array($row->status, [
                EmailCampaignRecipient::STATUS_DELIVERED,
                EmailCampaignRecipient::STATUS_SKIPPED,
            ], true)) {
                $row->update([
                    'status' => EmailCampaignRecipient::STATUS_FAILED,
                    'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
                ]);
            }
            Log::warning('Campaign send failed', [
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function claimPending(EmailCampaignRecipient $row, string $status, ?string $reason = null): bool
    {
        $payload = ['status' => $status];
        if ($reason !== null) {
            $payload['skip_reason'] = $reason;
        }

        return EmailCampaignRecipient::query()
            ->whereKey($row->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->update($payload) > 0;
    }

    protected function hasPending(EmailCampaign $campaign): bool
    {
        return EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->exists();
    }

    /**
     * A timeout or transient DB error must not wipe leftover pending rows.
     * Leave an unclaimed `queued` campaign for recoverStalled(); retry a
     * `sending` campaign a few times; only then markFailed().
     */
    protected function continueOrGiveUp(EmailCampaign $campaign): void
    {
        $campaign->refresh();
        if (in_array($campaign->status, [EmailCampaign::STATUS_SENT, EmailCampaign::STATUS_FAILED], true)) {
            return;
        }

        if ($campaign->status === EmailCampaign::STATUS_QUEUED) {
            return;
        }

        if ($campaign->status === EmailCampaign::STATUS_SENDING && $this->hasPending($campaign)) {
            $streak = max($this->failStreak, $campaign->currentFailStreak());
            if ($streak >= self::MAX_FAIL_STREAK) {
                $campaign->clearFailStreak();
                $this->markFailed($campaign);

                return;
            }

            $next = $streak + 1;
            $campaign->rememberFailStreak($next);
            SendEmailCampaignJob::dispatch($this->campaignId, $next);

            return;
        }

        if ($campaign->status === EmailCampaign::STATUS_SENDING) {
            $this->finalize($campaign);

            return;
        }

        $this->markFailed($campaign);
    }

    protected function finalize(EmailCampaign $campaign): void
    {
        $campaign->refresh();
        $campaign->recountRecipientTotals();
        $campaign->refresh();

        $campaign->update([
            'status' => $campaign->sent_count > 0
                ? EmailCampaign::STATUS_SENT
                : EmailCampaign::STATUS_FAILED,
            'sent_at' => now(),
        ]);
    }

    protected function markFailed(EmailCampaign $campaign): void
    {
        $campaign->refresh();
        if (in_array($campaign->status, [EmailCampaign::STATUS_SENT, EmailCampaign::STATUS_FAILED], true)) {
            return;
        }

        EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->update([
                'status' => EmailCampaignRecipient::STATUS_FAILED,
                'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
            ]);

        $campaign->recountRecipientTotals();
        $campaign->update([
            'status' => EmailCampaign::STATUS_FAILED,
            'sent_at' => now(),
        ]);
    }
}
