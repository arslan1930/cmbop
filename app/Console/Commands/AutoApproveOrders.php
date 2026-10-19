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
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\Orders\OrderRefundService;
use App\Services\Partners\PartnerSettlement;
use App\Services\Wallet\WalletLedgerService;
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
            ->whereLiveUrlSubmittedAtIsRecorded()
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
            ->where(function ($q) {
                $q->where('content_revision_requested', 'no')
                    ->orWhereNull('content_revision_requested');
            })
            ->whereHas('order', function ($q) {
                $q->where('status', 'review')
                    ->where('payment_status', 'paid');
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
            ->whereLiveUrlSubmittedAtIsRecorded()
            ->where('live_url_submitted_at', '<=', Carbon::now()->subHours($windowHours))
            ->where(function ($q) {
                $q->where('modification_requested', 'no')
                    ->orWhereNull('modification_requested');
            })
            ->where(function ($q) {
                $q->where('auto_approve_triggered', false)
                    ->orWhereNull('auto_approve_triggered');
            })
            ->where(function ($q) {
                $q->where('content_revision_requested', 'no')
                    ->orWhereNull('content_revision_requested');
            })
            ->whereHas('order', function ($q) {
                $q->where('status', 'review')
                    ->where('payment_status', 'paid');
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

                if ($order->payment_status !== 'paid') {
                    DB::rollBack();
                    $this->warn("Skip order #{$order->id}: payment is not complete ({$order->payment_status})");

                    continue;
                }

                $lockedItem = OrderItem::where('id', $orderItem->id)->lockForUpdate()->first();
                if (! $lockedItem || $lockedItem->auto_approve_triggered || $lockedItem->isPayoutComplete()) {
                    DB::rollBack();

                    continue;
                }

                if (OrderItem::autoApproveRequiresLiveUrlOk() && $lockedItem->live_url_check_ok === false) {
                    DB::rollBack();
                    $this->warn("Skip order item #{$lockedItem->id}: live URL health check failed");

                    continue;
                }

                if ($lockedItem->isContentRevisionRequested()) {
                    DB::rollBack();
                    $this->warn("Skip order item #{$lockedItem->id}: content revision still open");

                    continue;
                }

                if (! $lockedItem->isReadyForAutoApprove()) {
                    DB::rollBack();

                    continue;
                }

                $partnerSettled = class_exists(PartnerSettlement::class)
                    && app(PartnerSettlement::class)->settleOnApproval($lockedItem);

                $itemUpdate = [
                    'auto_approve_triggered' => true,
                    'auto_approve_at' => Carbon::now(),
                ];
                if (Schema::hasColumn('order_items', 'completed_at') && ! $lockedItem->completed_at) {
                    $itemUpdate['completed_at'] = Carbon::now();
                }
                if (Schema::hasColumn('order_items', 'publisher_status')) {
                    $itemUpdate['publisher_status'] = 'completed';
                }
                $lockedItem->update($itemUpdate);

                $schema = app(CheckoutSchemaService::class);
                $schema->ensureCheckoutTables();

                $itemCompletion = $schema->filterExistingColumns('order_items', [
                    'completed_at' => $lockedItem->completed_at ?? Carbon::now(),
                    'publisher_status' => 'completed',
                ]);
                if ($itemCompletion !== []) {
                    $lockedItem->forceFill($itemCompletion)->save();
                }

                $publisherRoleId = Wallet::publisherRoleId();
                $site = Site::find($lockedItem->site_id);

                if ($site) {
                    Site::refreshCompletedOrdersCount((int) $site->id);
                }

                if ($site && $site->publisher_id && $publisherRoleId && ! $partnerSettled) {
                    $publisher = User::find($site->publisher_id);

                    if ($publisher) {
                        $credit = $lockedItem->cappedPublisherCredit();
                        $publisherWallet = Wallet::lockOrCreateForRole(
                            $publisher->id,
                            $publisherRoleId,
                            $credit['currency']
                        );
                        $amount = $credit['amount'];
                        $advertiserPaid = $credit['advertiser_eur'];
                        if ($credit['capped']) {
                            Log::warning('Auto-approve: capping publisher payout to advertiser-paid amount', [
                                'order_id' => $order->id,
                                'order_item_id' => $lockedItem->id,
                                'advertiser_paid' => $advertiserPaid,
                                'uncapped_payout' => $credit['uncapped'],
                                'publisher_currency' => $credit['currency'],
                            ]);
                        }
                        $platformFee = $credit['platform_fee_eur'];
                        $publisherWallet->credit($amount);

                        try {
                            app(WalletLedgerService::class)->recordTransferIn(
                                $publisherWallet,
                                $amount,
                                $lockedItem,
                                'ORDER-ITEM-'.$lockedItem->id,
                                'Publisher earnings (auto-approve) for order #'.($order->order_number ?? $order->id),
                                [
                                    'order_id' => $order->id,
                                    'platform_fee' => $platformFee,
                                    'advertiser_paid' => (float) $lockedItem->price,
                                    'auto_approved' => true,
                                ]
                            );
                        } catch (\Throwable $ledgerError) {
                            Log::error('Auto-approve ledger write failed after publisher credit', [
                                'order_id' => $order->id,
                                'order_item_id' => $lockedItem->id,
                                'error' => $ledgerError->getMessage(),
                            ]);
                        }

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

                $siblings = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->get();
                $allComplete = $siblings->every(fn (OrderItem $item) => $this->itemIsCompleted($item));

                if ($allComplete) {
                    $order->update($schema->filterExistingColumns('orders', [
                        'status' => 'completed',
                        'completed_at' => Carbon::now(),
                    ]));

                    $advertiserRoleId = Wallet::advertiserRoleId();
                    $advertiserWallet = $advertiserRoleId
                        ? Wallet::lockForUserRole($order->user_id, $advertiserRoleId)
                        : null;

                    if ($advertiserWallet) {
                        // Wallet: consume the reserved line. Card / bonus: consume
                        // leftover promo so it is not trapped after settlement.
                        app(OrderRefundService::class)->consumeReservedForSettledOrder($order, $advertiserWallet);
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

    private function itemIsCompleted(OrderItem $item): bool
    {
        if ($item->auto_approve_triggered) {
            return true;
        }

        if (Schema::hasColumn('order_items', 'completed_at') && $item->completed_at) {
            return true;
        }

        return Schema::hasColumn('order_items', 'publisher_status')
            && $item->publisher_status === 'completed';
    }
}
