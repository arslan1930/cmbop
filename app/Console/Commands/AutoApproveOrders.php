<?php
// app/Console/Commands/AutoApproveOrders.php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
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
        $this->info('[' . Carbon::now() . '] Auto-approve check started...');
        
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
        
        $this->info('Found ' . $orderItems->count() . ' orders ready for auto-approval');
        
        $approvedCount = 0;
        
        foreach ($orderItems as $orderItem) {
            try {
                DB::beginTransaction();
                
                $order = $orderItem->order;
                
                // Update order item
                $orderItem->update([
                    'auto_approve_triggered' => true,
                    'auto_approve_at' => Carbon::now()
                ]);
                
                // Update order status
                $order->update([
                    'status' => 'completed'
                ]);
                
                // Get publisher role ID
                $publisherRoleId = \App\Models\Role::where('name', 'publisher')->value('id');
                
                // Get the site to find publisher
                $site = Site::find($orderItem->site_id);
                
                if ($site && $site->publisher_id) {
                    $publisher = User::find($site->publisher_id);
                    
                    if ($publisher) {
                        // Get or create publisher's wallet
                        $publisherWallet = Wallet::where('user_id', $publisher->id)
                            ->where('role_id', $publisherRoleId)
                            ->first();
                        
                        if (!$publisherWallet) {
                            $publisherWallet = Wallet::create([
                                'user_id' => $publisher->id,
                                'role_id' => $publisherRoleId,
                                'balance' => 0,
                                'reserved_balance' => 0,
                                'currency' => 'EUR'
                            ]);
                        }
                        
                        // Publisher gets listing base (+ sensitive); platform keeps 15% markup fee
                        $amount = $orderItem->publisherPayoutAmount();
                        $platformFee = $orderItem->platformFeeAmount();
                        $publisherWallet->increment('balance', $amount);
                        
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
                
                // If wallet payment, release reserved funds
                if ($order->payment_method === 'wallet') {
                    $advertiserRoleId = \App\Models\Role::where('name', 'advertiser')->value('id');
                    $advertiserWallet = Wallet::where('user_id', $order->user_id)
                        ->where('role_id', $advertiserRoleId)
                        ->first();
                    
                    if ($advertiserWallet) {
                        $advertiserWallet->decrement('reserved_balance', $order->total_amount);
                        $this->info("✓ Reserved funds released from advertiser wallet");
                    }
                }
                
                DB::commit();
                $approvedCount++;
                
                $this->info("✓ Auto-approved order #{$order->order_number}");
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Failed to auto-approve order: " . $e->getMessage());
                Log::error('Auto-approve failed: ' . $e->getMessage());
            }
        }
        
        $this->info('[' . Carbon::now() . '] Auto-approve completed. Approved: ' . $approvedCount);
        
        return Command::SUCCESS;
    }
}