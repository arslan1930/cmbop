<?php
// app/Console/Commands/AutoApproveOrders.php

namespace App\Console\Commands;

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

class AutoApproveOrders extends Command
{
    protected $signature = 'orders:auto-approve';
    protected $description = 'Auto approve orders after 48 hours of live URL submission';

    public function handle()
    {
        $this->info('['.Carbon::now().'] Auto-approve check started...');

        // Find orders ready for auto-approval.
        // Treat NULL modification_requested like 'no' so older rows are not skipped forever.
        $orderItems = OrderItem::whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->whereNotNull('live_url_submitted_at')
            ->where('live_url_submitted_at', '<=', Carbon::now()->subHours(48))
            ->where(function ($query) {
                $query->where('modification_requested', 'no')
                    ->orWhereNull('modification_requested');
            })
            ->where(function ($query) {
                $query->where('auto_approve_triggered', false)
                    ->orWhereNull('auto_approve_triggered');
            })
            ->whereHas('order', function ($query) {
                $query->where('status', 'review');
            })
            ->get();

        $this->info('Found '.$orderItems->count().' orders ready for auto-approval');

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

                // Update order item
                $lockedItem->update([
                    'auto_approve_triggered' => true,
                    'auto_approve_at' => Carbon::now(),
                ]);

                // Update order status
                $order->update([
                    'status' => 'completed',
                ]);

                $publisherRoleId = Wallet::publisherRoleId();

                // Get the site to find publisher
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

                // If wallet payment, consume reserved funds
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
                        (float) $transferAmount
                    );
                } else {
                    $notifications->notifyOrderCompleted($order->fresh());
                }

                $this->info("✓ Auto-approved order #{$order->order_number}");
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('Failed to auto-approve order: '.$e->getMessage());
                Log::error('Auto-approve failed: '.$e->getMessage());
            }
        }

        $this->info('['.Carbon::now().'] Auto-approve completed. Approved: '.$approvedCount);

        return Command::SUCCESS;
    }
}
