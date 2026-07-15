<?php
// app/Http/Controllers/Api/StripeWebhookController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\StripeWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Log::info('Stripe webhook received');
        
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            if (!$endpointSecret) {
                Log::error('Stripe webhook secret not configured');
                return response()->json(['error' => 'Webhook not configured'], 500);
            }

            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            $eventType = $event->type;
            $eventId = $event->id;

            Log::info('Processing webhook event', [
                'event_id' => $eventId,
                'event_type' => $eventType
            ]);

            // Prevent duplicate processing
            if (StripeWebhookLog::where('event_id', $eventId)->exists()) {
                Log::info('Webhook already processed', ['event_id' => $eventId]);
                return response()->json(['status' => 'duplicate'], 200);
            }

            // Log webhook event
            StripeWebhookLog::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => json_encode($event),
                'processed' => false
            ]);

            // Handle checkout.session.completed event
            if ($eventType === 'checkout.session.completed') {
                $session = $event->data->object;
                $this->routePaymentToCorrectTable($session);
            }

            // Mark as processed
            StripeWebhookLog::where('event_id', $eventId)->update(['processed' => true]);

            return response()->json(['status' => 'success'], 200);

        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Route payment to the correct table based on metadata type
     */
    private function routePaymentToCorrectTable($session)
    {
        $metadata = $session->metadata ?? [];
        
        // METHOD 1: Check explicit 'type' field in metadata
        $paymentType = $metadata->type ?? $metadata['type'] ?? null;
        
        Log::info('Routing payment', [
            'payment_type' => $paymentType,
            'metadata' => json_decode(json_encode($metadata), true)
        ]);
        
        // Route based on payment type
        switch ($paymentType) {
            case 'wallet_deposit':
            case 'deposit':
                $this->handleWalletDeposit($session);
                break;
                
            case 'order_payment':
            case 'order':
                $this->handleOrderPayment($session);
                break;
                
            default:
                // METHOD 2: Check for specific identifiers if type is not set
                $this->detectPaymentTypeByMetadata($session);
                break;
        }
    }
    
    /**
     * Detect payment type by checking metadata fields
     */
    private function detectPaymentTypeByMetadata($session)
    {
        $metadata = $session->metadata ?? [];
        
        // Check for deposit-specific fields
        if (isset($metadata->deposit_id) || isset($metadata['deposit_id'])) {
            Log::info('Detected deposit payment by deposit_id field');
            $this->handleWalletDeposit($session);
        }
        // Check for order-specific fields
        elseif (isset($metadata->reference_code) || isset($metadata['reference_code'])) {
            Log::info('Detected order payment by reference_code field');
            $this->handleOrderPayment($session);
        }
        else {
            Log::warning('Unable to determine payment type', [
                'metadata' => json_decode(json_encode($metadata), true)
            ]);
        }
    }

    /**
     * Handle wallet deposit payment - Saves to deposit_requests table
     */
    private function handleWalletDeposit($session)
    {
        try {
            $metadata = $session->metadata ?? [];
            
            // Try to get deposit_id from metadata (could be object or array)
            $depositId = is_object($metadata) ? ($metadata->deposit_id ?? null) : ($metadata['deposit_id'] ?? null);
            $amount = is_object($metadata) ? ($metadata->amount ?? null) : ($metadata['amount'] ?? null);
            $referenceCode = is_object($metadata) ? ($metadata->reference_code ?? null) : ($metadata['reference_code'] ?? null);
            
            Log::info('Processing wallet deposit', [
                'deposit_id' => $depositId,
                'session_id' => $session->id,
                'amount' => $amount
            ]);

            // If deposit_id is provided in metadata, use it
            if ($depositId) {
                $deposit = DepositRequest::find($depositId);
                
                if (!$deposit) {
                    Log::warning('Deposit not found', ['deposit_id' => $depositId]);
                    return;
                }
                
                if ($deposit->status === 'completed') {
                    Log::info('Deposit already completed, skipping');
                    return;
                }
                
                DB::transaction(function () use ($deposit, $session) {
                    $lockedDeposit = DepositRequest::where('id', $deposit->id)->lockForUpdate()->first();
                    if (!$lockedDeposit || $lockedDeposit->status === 'completed') {
                        return;
                    }

                    $lockedDeposit->update([
                        'stripe_session_id' => $session->id,
                        'stripe_payment_intent_id' => $session->payment_intent,
                        'stripe_response' => json_encode($session),
                        'status' => 'completed',
                        'approved_at' => now(),
                        'paid_at' => now(),
                    ]);

                    $advertiserRoleId = Wallet::advertiserRoleId();
                    if (!$advertiserRoleId) {
                        throw new \RuntimeException('Advertiser role not configured');
                    }

                    $wallet = Wallet::lockOrCreateForRole($lockedDeposit->user_id, $advertiserRoleId);
                    $wallet->credit((float) $lockedDeposit->amount);
                });
                
                Log::info('Deposit completed', ['deposit_id' => $deposit->id]);
                return;
            }
            
            // If no deposit_id, create one (fallback for direct payments)
            $userId = is_object($metadata) ? ($metadata->user_id ?? null) : ($metadata['user_id'] ?? null);
            $stripeAmount = $session->amount_total / 100;
            $finalAmount = $amount ?? $stripeAmount;
            
            if ($userId) {
                DB::transaction(function () use ($userId, $session, $finalAmount, $referenceCode) {
                    // Idempotency: skip if this Stripe session was already credited
                    $existing = DepositRequest::where('stripe_session_id', $session->id)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        Log::info('Deposit already exists for Stripe session', [
                            'deposit_id' => $existing->id,
                            'session_id' => $session->id,
                        ]);
                        return;
                    }

                    $deposit = DepositRequest::create([
                        'user_id' => $userId,
                        'reference_code' => $referenceCode ?? str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT),
                        'amount' => $finalAmount,
                        'payment_method' => 'card',
                        'status' => 'completed',
                        'stripe_session_id' => $session->id,
                        'stripe_payment_intent_id' => $session->payment_intent,
                        'stripe_response' => json_encode($session),
                        'approved_at' => now(),
                        'paid_at' => now(),
                    ]);

                    $advertiserRoleId = Wallet::advertiserRoleId();
                    if (!$advertiserRoleId) {
                        throw new \RuntimeException('Advertiser role not configured');
                    }

                    $wallet = Wallet::lockOrCreateForRole($userId, $advertiserRoleId);
                    $wallet->credit((float) $finalAmount);
                    
                    Log::info('Deposit created from webhook', ['deposit_id' => $deposit->id]);
                });
            }
            
        } catch (\Exception $e) {
            Log::error('Error processing wallet deposit: ' . $e->getMessage());
        }
    }

    /**
     * Handle order payment - Saves to orders table
     */
    private function handleOrderPayment($session)
    {
        try {
            $metadata = $session->metadata ?? [];
            
            // Try to get reference_code from metadata (could be object or array)
            $referenceCode = is_object($metadata) ? ($metadata->reference_code ?? null) : ($metadata['reference_code'] ?? null);
            
            Log::info('Processing order payment', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id
            ]);

            if (!$referenceCode) {
                Log::warning('No reference_code found for order payment');
                return;
            }

            // Find pending orders with this reference code
            $orders = Order::where('reference_code', $referenceCode)
                ->where('payment_status', 'pending')
                ->get();

            if ($orders->isEmpty()) {
                Log::warning('No pending orders found', ['reference_code' => $referenceCode]);
                return;
            }

            DB::transaction(function () use ($orders, $session) {
                foreach ($orders as $order) {
                    $order->update([
                        'stripe_session_id' => $session->id,
                        'stripe_payment_intent_id' => $session->payment_intent,
                        'stripe_response' => json_encode($session->toArray()),
                        'paid_at' => now(),
                        'payment_status' => 'paid',
                        'status' => 'processing'
                    ]);
                    
                    Log::info('Order updated', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number
                    ]);
                }
            });

            Log::info('Order payment completed', [
                'reference_code' => $referenceCode,
                'orders_updated' => $orders->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing order payment: ' . $e->getMessage());
        }
    }
}