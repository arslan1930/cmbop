<?php

// app/Console/Commands/AutoApproveOrders.php

namespace App\Console\Commands;

use App\Mail\AutoApproveReminderMail;
use App\Mail\OrderApprovedByAdvertiser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class AutoApproveOrders extends Command
{
    protected $signature = 'orders:auto-approve';

    protected $description = 'Send auto-approve reminders and auto-complete orders after the configured review window';

    public function handle()
    {
        $windowHours = OrderItem::autoApproveHours();
        $this->info('['.Carbon::now().'] Auto-approve check started (window: '.$windowHours.'h)...');

        $reminded = $this->sendReminders();
        $approved = $this->autoApproveDueOrders($windowHours);

        $this->info('['.Carbon::now().'] Auto-approve finished. Reminders: '.$reminded.'; Approved: '.$approved);

        return Command::SUCCESS;
    }

    protected function sendReminders(): int
    {
        $reminderBefore = OrderItem::autoApproveReminderHoursBefore();
        if ($reminderBefore <= 0) {
            return 0;
        }

        if (! Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
            $this->warn('auto_approve_reminder_sent_at column missing — skip reminders');

            return 0;
        }

        $windowHours = OrderItem::autoApproveHours();
        // Submitted long enough ago that <= reminderBefore hours remain,
        // but not yet past the full auto-approve window.
        $reminderEligibleAt = Carbon::now()->subHours(max(0, $windowHours - $reminderBefore));
        $notYetDueAt = Carbon::now()->subHours($windowHours);

        $query = OrderItem::query()
            ->whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->whereNotNull('live_url_submitted_at')
            ->where('live_url_submitted_at', '<=', $reminderEligibleAt)
            ->where('live_url_submitted_at', '>', $notYetDueAt)
            ->whereNull('auto_approve_reminder_sent_at')
            ->where(function ($q) {
                $q->where('modification_requested', 'no')
                    ->orWhereNull('modification_requested');
            })
            ->where(function ($q) {
                $q->where('auto_approve_triggered', false)
                    ->orWhereNull('auto_approve_triggered');
            })
            ->whereHas('order', function ($q) {
                $q->where('status', 'review');
            });

        if (OrderItem::autoApproveRequiresLiveUrlOk() && Schema::hasColumn('order_items', 'live_url_check_ok')) {
            $query->where(function ($q) {
                $q->whereNull('live_url_check_ok')
                    ->orWhere('live_url_check_ok', true);
            });
        }

        $items = $query->limit(200)->get();
        $this->info('Found '.$items->count().' order(s) for auto-approve reminder');

        $sent = 0;
        $notifications = app(InAppNotificationService::class);

        foreach ($items as $item) {
            try {
                $order = $item->order;
                if (! $order || $order->status !== 'review') {
                    continue;
                }

                if (! $item->isReadyForAutoApproveReminder()) {
                    continue;
                }

                $hoursRemaining = max(1, (int) $item->getAutoApproveHoursRemaining());
                $site = $item->site_id ? Site::find($item->site_id) : null;
                $advertiser = User::find($order->user_id);

                $item->update(['auto_approve_reminder_sent_at' => now()]);

                if ($advertiser?->email) {
                    try {
                        Mail::to($advertiser->email)->send(
                            new AutoApproveReminderMail($order, $item, $site, $hoursRemaining)
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Auto-approve reminder email failed', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $notifications->notifyAutoApproveReminder($order, $item, $hoursRemaining);
                $sent++;
                $this->info("✓ Reminder sent for order #{$order->order_number} (~{$hoursRemaining}h left)");
            } catch (\Throwable $e) {
                Log::error('Auto-approve reminder failed: '.$e->getMessage(), [
                    'order_item_id' => $item->id,
                ]);
                $this->error('Reminder failed for item #'.$item->id.': '.$e->getMessage());
            }
        }

        return $sent;
    }

    protected function autoApproveDueOrders(int $windowHours): int
    {
        $query = OrderItem::query()
            ->whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->whereNotNull('live_url_submitted_at')
            ->where('live_url_submitted_at', '<=', Carbon::now()->subHours($windowHours))
            ->where(function ($q) {
                $q->where('modification_requested', 'no')
                    ->orWhereNull('modification_requested');
            })
            ->where(function ($q) {
                $q->where('auto_approve_triggered', false)
                    ->orWhereNull('auto_approve_triggered');
            })
            ->whereHas('order', function ($q) {
                $q->where('status', 'review');
            });

        if (OrderItem::autoApproveRequiresLiveUrlOk() && Schema::hasColumn('order_items', 'live_url_check_ok')) {
            $query->where(function ($q) {
                $q->whereNull('live_url_check_ok')
                    ->orWhere('live_url_check_ok', true);
            });
        }

        $orderItems = $query->get();
        $this->info('Found '.$orderItems->count().' order(s) ready for auto-approval');

        $approvedCount = 0;
        $notifications = app(InAppNotificationService::class);

        foreach ($orderItems as $orderItem) {
            $transferPublisherId = null;
            $transferAmount = null;
            $mailPayload = null;

            try {
                DB::beginTransaction();

                $order = Order::where('id', $orderItem->order_id)->lockForUpdate()->first();
                if (! $order || $order->status === 'completed' || $order->status === 'cancelled') {
                    DB::rollBack();

                    continue;
                }

                $lockedItem = OrderItem::where('id', $orderItem->id)->lockForUpdate()->first();
                if (! $lockedItem || $lockedItem->auto_approve_triggered) {
                    DB::rollBack();

                    continue;
                }

                if (OrderItem::autoApproveRequiresLiveUrlOk() && $lockedItem->live_url_check_ok === false) {
                    DB::rollBack();
                    $this->warn("Skip order item #{$lockedItem->id}: live URL health check failed");

                    continue;
                }

                $lockedItem->update([
                    'auto_approve_triggered' => true,
                    'auto_approve_at' => Carbon::now(),
                ]);

                $order->update([
                    'status' => 'completed',
                ]);

                $publisherRoleId = Wallet::publisherRoleId();
                $site = Site::find($lockedItem->site_id);

                if ($site) {
                    Site::refreshCompletedOrdersCount((int) $site->id);
                }

                if ($site && $site->publisher_id && $publisherRoleId) {
                    $publisher = User::find($site->publisher_id);

                    if ($publisher) {
                        $publisherWallet = Wallet::lockOrCreateForRole($publisher->id, $publisherRoleId);
                        $amount = (float) $lockedItem->publisherPayoutAmount();
                        $platformFee = (float) $lockedItem->platformFeeAmount();
                        $publisherWallet->credit($amount);

                        $transferPublisherId = $publisher->id;
                        $transferAmount = $amount;
                        $mailPayload = [$order, $lockedItem, $site, $publisher];

                        $this->info("✓ Payment of €{$amount} transferred to publisher #{$publisher->id} (platform fee €{$platformFee})");
                        Log::info('Auto-approve publisher payout', [
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'publisher_id' => $publisher->id,
                            'advertiser_paid' => (float) $orderItem->price,
                            'publisher_payout' => $amount,
                            'platform_fee' => $platformFee,
                        ]);
                    }
                }

                if ($order->payment_method === 'wallet') {
                    $advertiserRoleId = Wallet::advertiserRoleId();
                    $advertiserWallet = $advertiserRoleId
                        ? Wallet::lockForUserRole($order->user_id, $advertiserRoleId)
                        : null;

                    if ($advertiserWallet) {
                        $advertiserWallet->consumeReserved((float) $order->total_amount);
                        $this->info('✓ Reserved funds released from advertiser wallet');
                    }
                }

                DB::commit();
                $approvedCount++;

                if ($mailPayload) {
                    [$mailOrder, $mailItem, $mailSite, $mailPublisher] = $mailPayload;
                    try {
                        Mail::to($mailPublisher->email)->send(
                            new OrderApprovedByAdvertiser($mailOrder, $mailItem, $mailSite)
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Auto-approve publisher email failed', [
                            'order_id' => $mailOrder->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $notifications->notifyOrderCompleted(
                        $mailOrder,
                        $mailPublisher,
                        (float) $transferAmount,
                        true
                    );
                } else {
                    $notifications->notifyOrderCompleted($order->fresh(), null, null, true);
                }

                $this->info("✓ Auto-approved order #{$order->order_number}");
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('Failed to auto-approve order: '.$e->getMessage());
                Log::error('Auto-approve failed: '.$e->getMessage());
            }
        }

        return $approvedCount;
    }
}
