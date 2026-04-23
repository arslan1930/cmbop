<?php
// app/Http/Controllers/Api/StripeWebhookController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
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
            // Enforce signature verification (no unsafe fallback)
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
                $this->handleCheckoutSessionCompleted($session);
            }

            // Mark as processed
            StripeWebhookLog::where('event_id', $eventId)->update(['processed' => true]);

            return response()->json(['status' => 'success'], 200);

        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error: ' . $e->getMessage());
            Log::error('Stripe webhook trace: ' . $e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleCheckoutSessionCompleted($session)
    {
        try {
            $metadata = $session->metadata ?? [];
            $depositId = $metadata->deposit_id ?? null;

            Log::info('Processing checkout.session.completed', [
                'deposit_id' => $depositId,
                'session_id' => $session->id,
                'metadata' => $metadata
            ]);

            if (!$depositId) {
                Log::warning('No deposit_id found in session metadata');
                return;
            }

            $deposit = DepositRequest::find($depositId);

            if (!$deposit) {
                Log::warning('Deposit not found', ['deposit_id' => $depositId]);
                return;
            }

            // Prevent duplicate crediting
            if ($deposit->status === 'completed') {
                Log::info('Deposit already completed, skipping', [
                    'deposit_id' => $deposit->id
                ]);
                return;
            }

            Log::info('Deposit found', [
                'deposit_id' => $deposit->id,
                'current_status' => $deposit->status,
                'amount' => $deposit->amount
            ]);

            // Optional: verify amount (Stripe sends amount in cents)
            if (isset($session->amount_total)) {
                $stripeAmount = $session->amount_total / 100;
                if ((float)$deposit->amount !== (float)$stripeAmount) {
                    Log::error('Amount mismatch', [
                        'deposit_amount' => $deposit->amount,
                        'stripe_amount' => $stripeAmount
                    ]);
                    return;
                }
            }

            DB::transaction(function () use ($deposit, $session) {

                // Update deposit
                $deposit->update([
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent_id' => $session->payment_intent,
                    'stripe_response' => json_encode($session),
                    'status' => 'completed',
                    'approved_at' => now(),
                    'paid_at' => now(),
                ]);

                // Update wallet safely
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $deposit->user_id],
                    ['balance' => 0, 'reserved_balance' => 0, 'role_id' => 1]
                );

                $wallet->increment('balance', $deposit->amount);

                Log::info('Deposit completed via webhook', [
                    'deposit_id' => $deposit->id,
                    'user_id' => $deposit->user_id,
                    'amount' => $deposit->amount,
                    'new_balance' => $wallet->balance
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Error processing checkout session completed: ' . $e->getMessage());
            Log::error('Error trace: ' . $e->getTraceAsString());
        }
    }
}