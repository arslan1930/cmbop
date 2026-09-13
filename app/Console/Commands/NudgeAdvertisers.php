<?php

namespace App\Console\Commands;

use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\AdvertiserReviewNudge;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Services\Reminders\OrderDeadline;
use App\Services\Reminders\ReminderFatigueGuard;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps advertisers moving, in two directions.
 *
 * The review nudge fills the middle of the auto-approve window, which previously
 * held nothing between "your link is live" and the 24-hour warning. The stalled
 * notice tells them their publisher is late before they have to come and ask —
 * silence is what turns a delay into a support ticket.
 */
class NudgeAdvertisers extends Command
{
    protected $signature = 'orders:nudge-advertisers {--dry-run : Report what would be sent without sending}';

    protected $description = 'Remind advertisers to review live links and tell them when a publisher is late';

    private bool $dryRun = false;

    private OrderDeadline $deadlines;

    public function handle(
        ReminderFatigueGuard $guard,
        OrderDeadline $deadlines,
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
    ): int {
        $this->deadlines = $deadlines;
        $this->dryRun = (bool) $this->option('dry-run');

        if (! Schema::hasColumn('order_items', 'review_nudge_sent_at')) {
            $this->warn('Reminder tracking columns missing — run migrations first.');

            return Command::SUCCESS;
        }

        $reviews = $this->nudgeReviews($guard, $mailer, $bells);
        $stalled = $this->noticeStalled($guard, $mailer, $bells);

        $this->info(sprintf(
            '%sAdvertiser nudges — review: %d, stalled: %d',
            $this->dryRun ? '[dry run] ' : '',
            $reviews,
            $stalled
        ));

        return Command::SUCCESS;
    }

    /**
     * Live URL submitted, advertiser has not looked, and there is still real time
     * left on the clock.
     */
    private function nudgeReviews(ReminderFatigueGuard $guard, EmailNotificationService $mailer, InAppNotificationService $bells): int
    {
        $window = OrderItem::autoApproveHours();
        $fraction = (float) config('reminders.advertiser_review.nudge_at_fraction', 0.33);
        $after = max(1, (int) round($window * $fraction));

        // Do not overlap the existing 24h-before warning: if the later reminder
        // is already due, let that one carry the message.
        $laterReminderAt = max(0, $window - OrderItem::autoApproveReminderHoursBefore());
        if ($laterReminderAt > 0 && $after >= $laterReminderAt) {
            $after = max(1, $laterReminderAt - 1);
        }

        $items = OrderItem::query()
            ->whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->whereLiveUrlSubmittedAtIsRecorded()
            ->where('live_url_submitted_at', '<=', now()->subHours($after))
            ->where('live_url_submitted_at', '>', now()->subHours($window))
            ->whereNull('review_nudge_sent_at')
            ->where(fn ($q) => $q->where('modification_requested', 'no')->orWhereNull('modification_requested'))
            ->whereHas('order', function ($q) {
                $q->where('status', 'review')
                    ->where('payment_status', 'paid');
            })
            ->with('order')
            ->limit(300)
            ->get();

        $sent = 0;

        foreach ($items as $item) {
            try {
                $order = $item->order;
                $advertiser = $order ? User::find($order->user_id) : null;

                if (! $order || ! $advertiser?->email || ! $item->live_url_submitted_at) {
                    continue;
                }

                if (! AdvertiserOrderStatus::isLiveAdvertiserWork($order)) {
                    continue;
                }

                if (! $guard->allows($advertiser)) {
                    $this->line('- skipped (daily cap) advertiser #'.$advertiser->id);

                    continue;
                }

                $completesAt = $item->live_url_submitted_at->copy()->addHours($window);

                if ($this->dryRun) {
                    $this->line("- would send review nudge for order #{$order->order_number}");
                    $sent++;

                    continue;
                }

                $queued = $mailer->sendReminder($advertiser, new AdvertiserReviewNudge(
                    $advertiser,
                    $order,
                    $item,
                    $item->site_id ? Site::find($item->site_id) : null,
                    $completesAt
                ));

                if (! $queued) {
                    $this->line("- skipped (mail blocked) review nudge for order #{$order->order_number}");

                    continue;
                }

                $item->update(['review_nudge_sent_at' => now()]);
                $guard->record($advertiser);

                try {
                    $bells->notifyAdvertiserReviewNudge($order, $item, $completesAt);
                } catch (\Throwable $e) {
                    Log::warning('Review nudge bell failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $sent++;
                $this->info("✓ Review nudge for order #{$order->order_number}");
            } catch (\Throwable $e) {
                Log::error('Advertiser review nudge failed', [
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * The publisher is well past their turnaround and the advertiser has heard
     * nothing. Told once per order item.
     */
    private function noticeStalled(
        ReminderFatigueGuard $guard,
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
    ): int {
        $after = max(1, (int) config('reminders.advertiser_stalled.hours_after_deadline', 72));

        $items = OrderItem::query()
            ->whereAcceptedAtIsRecorded()
            ->where(fn ($q) => $q->whereNull('live_url')->orWhere('live_url', ''))
            ->whereNull('stalled_notice_sent_at')
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid')->whereIn('status', ['processing', 'pending']);
            })
            ->with(['order', 'site'])
            ->limit(300)
            ->get();

        $sent = 0;

        foreach ($items as $item) {
            try {
                $order = $item->order;
                $site = $item->site;

                if (! $order || ! $site) {
                    continue;
                }

                $deadline = $this->deadlines->for($item, $order, $site);

                if (! $deadline || $deadline->copy()->addHours($after)->isFuture()) {
                    continue;
                }

                $advertiser = User::find($order->user_id);

                if (! $advertiser?->email) {
                    continue;
                }

                $hoursOverdue = max(1, (int) $deadline->diffInHours(now()));

                if ($this->dryRun) {
                    $this->line("- would send stalled notice for order #{$order->order_number}");
                    $sent++;

                    continue;
                }

                // Bypasses the fatigue cap deliberately: being told your order is
                // late is service, not a nudge, and it is sent once per order.
                $queued = $mailer->sendReminder($advertiser, new AdvertiserOrderStalledNotice(
                    $advertiser,
                    $order,
                    $item,
                    $site,
                    $deadline,
                    $hoursOverdue
                ));

                if (! $queued) {
                    $this->line("- skipped (mail blocked) stalled notice for order #{$order->order_number}");

                    continue;
                }

                $item->update(['stalled_notice_sent_at' => now()]);

                try {
                    $bells->notifyAdvertiserOrderStalled($order, $item, $hoursOverdue);
                } catch (\Throwable $e) {
                    Log::warning('Stalled bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }

                $sent++;
                $this->info("✓ Stalled notice for order #{$order->order_number}");
            } catch (\Throwable $e) {
                Log::error('Advertiser stalled notice failed', [
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
