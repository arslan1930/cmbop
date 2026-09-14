<?php

// app/Http/Controllers/Api/StripeWebhookController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Site;
use App\Models\StripeWebhookLog;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\SitePromotionService;
use App\Services\StripePaymentService;
use App\Services\WalletStripeDepositService;
use App\Support\UserMessages;
use App\Support\WebhookPayloadRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Log::info('Stripe webhook received');

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            if (! $endpointSecret) {
                Log::error('Stripe webhook secret not configured');

                return response()->json(['error' => UserMessages::get('payment.webhook_unavailable')], 503);
            }

            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            $eventType = $event->type;
            $eventId = $event->id;

            Log::info('Processing webhook event', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            // Only skip when a prior delivery fully succeeded.
            $existingLog = $this->findWebhookLog($eventId);
            if ($existingLog && $existingLog->processed) {
                Log::info('Webhook already processed', ['event_id' => $eventId]);

                return response()->json(['status' => 'duplicate'], 200);
            }

            if (! $existingLog) {
                $this->recordWebhookLog($eventId, $eventType, WebhookPayloadRedactor::stripe($event));
            }

            if ($eventType === 'checkout.session.completed') {
                $this->routeCheckoutSessionCompleted($event->data->object);
            }

            // Session expiry is definitive. Do not mark failed on payment_intent.payment_failed:
            // Checkout allows in-session card retries and bonus may still be reserved.
            if ($eventType === 'checkout.session.expired') {
                $this->handleOrderCheckoutFailed($event->data->object, 'Checkout session expired');
            }

            if ($eventType === 'payment_intent.succeeded') {
                $this->routePaymentIntentSucceeded($event->data->object);
            }

            if ($eventType === 'charge.refunded') {
                $this->routeChargeRefunded($event->data->object);
            }

            $this->markWebhookLogProcessed($eventId);

            return response()->json(['status' => 'success'], 200);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => UserMessages::get('payment.webhook_signature')], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook error: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);

            return response()->json(['error' => UserMessages::get('payment.webhook_failed')], 500);
        }
    }

    private function routeCheckoutSessionCompleted(object $session): void
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $paymentType = $metadata['type'] ?? null;

        Log::info('Routing checkout.session.completed', [
            'payment_type' => $paymentType,
            'session_id' => $session->id ?? null,
        ]);

        switch ($paymentType) {
            case 'wallet_deposit':
            case 'deposit':
                $this->handleWalletDepositSession($session);
                break;

            case 'order_payment':
            case 'order':
                $this->handleOrderPaymentSession($session);
                break;

            case 'site_feature':
                $this->handleSiteFeatureSession($session);
                break;

            default:
                $this->detectPaymentTypeByMetadata($session, $metadata);
                break;
        }
    }

    private function routePaymentIntentSucceeded(object $intent): void
    {
        $metadata = $this->metaArray($intent->metadata ?? null);
        $paymentType = $metadata['type'] ?? null;

        Log::info('Routing payment_intent.succeeded', [
            'payment_type' => $paymentType,
            'payment_intent_id' => $intent->id ?? null,
        ]);

        switch ($paymentType) {
            case 'wallet_deposit':
            case 'deposit':
                app(WalletStripeDepositService::class)->creditFromPaymentIntentObject($intent);
                break;

            case 'order_payment':
            case 'order':
                $this->handleOrderPaymentIntent($intent, $metadata);
                break;

            default:
                Log::info('Ignoring payment_intent.succeeded without known type', [
                    'payment_intent_id' => $intent->id ?? null,
                    'type' => $paymentType,
                ]);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function detectPaymentTypeByMetadata(object $session, array $metadata): void
    {
        if (isset($metadata['deposit_id'])) {
            Log::info('Detected deposit payment by deposit_id field');
            $this->handleWalletDepositSession($session);

            return;
        }

        if (isset($metadata['reference_code'])) {
            // Wallet deposits also carry reference_code. Without an explicit
            // order type, guessing here could settle catalog orders from a
            // top-up. Typed order_payment / order sessions are routed above.
            Log::warning('Ignoring untyped checkout session with reference_code', [
                'session_id' => $session->id ?? null,
                'type' => $metadata['type'] ?? null,
            ]);

            return;
        }

        Log::warning('Unable to determine payment type', ['metadata' => $metadata]);
    }

    private function handleWalletDepositSession(object $session): void
    {
        app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);
    }

    /**
     * Card Add Funds clawback. Order refunds are ignored here — they have
     * their own billing path. Only a matching wallet deposit is reversed.
     */
    private function routeChargeRefunded(object $charge): void
    {
        $metadata = $this->metaArray($charge->metadata ?? null);
        $paymentIntentId = is_string($charge->payment_intent ?? null)
            ? $charge->payment_intent
            : (string) ($charge->payment_intent->id ?? '');
        $sessionId = (string) ($metadata['checkout_session'] ?? '');
        $refunds = $charge->refunds->data ?? $charge->refunds['data'] ?? [];
        $refundId = (string) (is_array($refunds)
            ? ($refunds[0]['id'] ?? $refunds[0]->id ?? '')
            : ($refunds[0]->id ?? ''));
        $amountRefunded = isset($charge->amount_refunded)
            ? StripePaymentService::fromCents((int) $charge->amount_refunded)
            : 0.0;

        if ($paymentIntentId === '' && $sessionId === '') {
            Log::info('Ignoring charge.refunded without payment_intent', [
                'charge_id' => $charge->id ?? null,
            ]);

            return;
        }

        $debited = app(WalletStripeDepositService::class)->reverseFromRefund(
            $paymentIntentId,
            $refundId,
            $amountRefunded,
            $sessionId
        );

        Log::info('Routed charge.refunded', [
            'charge_id' => $charge->id ?? null,
            'payment_intent_id' => $paymentIntentId,
            'wallet_debited' => $debited,
        ]);
    }

    private function handleOrderCheckoutFailed(object $session, string $reason): void
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $paymentType = $metadata['type'] ?? null;
        $referenceCode = $metadata['reference_code'] ?? null;

        if (! $referenceCode) {
            return;
        }

        // Same rule as completed-session routing: wallet deposits also carry
        // reference_code. An untyped expiry must not fail colliding card
        // checkouts or refund their reserved bonus.
        if (! in_array($paymentType, ['order_payment', 'order'], true)) {
            Log::warning('Ignoring checkout.session.expired without explicit order type', [
                'session_id' => $session->id ?? null,
                'type' => $paymentType,
            ]);

            return;
        }

        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : null;
        $bonusFallback = isset($metadata['bonus_applied']) ? round((float) $metadata['bonus_applied'], 2) : null;
        app(OrderPaymentService::class)->markOrdersFailedFromReference(
            $referenceCode,
            $reason,
            $userId && $userId > 0 ? $userId : null,
            $bonusFallback
        );
    }

    private function handleOrderPaymentSession(object $session): void
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $referenceCode = $metadata['reference_code'] ?? null;

        Log::info('Processing order payment webhook', [
            'reference_code' => $referenceCode,
            'session_id' => $session->id ?? null,
        ]);

        if (! $referenceCode) {
            throw new \RuntimeException('No reference_code found for order payment session');
        }

        $paymentStatus = $session->payment_status ?? null;
        if ($paymentStatus !== 'paid') {
            throw new \RuntimeException('order_payment session not paid: '.($paymentStatus ?? 'missing'));
        }

        $paymentService = app(OrderPaymentService::class);
        $newlyPaid = $paymentService->markOrdersPaidFromStripeSession($referenceCode, $session);

        if ($newlyPaid->isEmpty()) {
            $existingPaid = Order::where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'paid')
                ->count();

            if ($existingPaid > 0 && $paymentService->getPendingCheckout($referenceCode) === null) {
                $paymentService->creditCapturedCardWhenAlreadySettled($referenceCode, $session);
                Log::info('Order payment already finalized (idempotent webhook)', [
                    'reference_code' => $referenceCode,
                    'paid_count' => $existingPaid,
                ]);

                return;
            }

            // Stripe-first checkouts store a cache package and create orders only after pay.
            // Materialize via finalize if the browser never hit the success URL.
            // A reused reference can already have paid rows and a new pending package.
            $newlyPaid = $paymentService->finalizeStripeFirstCheckout($referenceCode, $session);

            if ($newlyPaid->isEmpty()) {
                $credited = $paymentService->walletCreditForUnfulfillableCardCheckout($referenceCode);
                if ($credited > 0) {
                    Log::warning('Stripe webhook settled without catalog-visible lines', [
                        'reference_code' => $referenceCode,
                        'session_id' => $session->id ?? null,
                        'wallet_credit' => $credited,
                    ]);

                    return;
                }

                throw new \RuntimeException('No pending card orders or checkout package found for webhook ref '.$referenceCode);
            }
        }

        $paymentService->notifyPublishersOfPaidOrders($newlyPaid);

        Log::info('Order payment completed via webhook', [
            'reference_code' => $referenceCode,
            'orders_updated' => $newlyPaid->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function handleOrderPaymentIntent(object $intent, array $metadata): void
    {
        $referenceCode = $metadata['reference_code'] ?? null;
        if (! $referenceCode) {
            throw new \RuntimeException('No reference_code on order_payment PaymentIntent');
        }

        $intentStatus = $intent->status ?? null;
        if ($intentStatus !== 'succeeded') {
            throw new \RuntimeException('order_payment PaymentIntent not succeeded: '.($intentStatus ?? 'missing'));
        }

        $paymentService = app(OrderPaymentService::class);
        $newlyPaid = $paymentService->markOrdersPaidFromPaymentIntent($referenceCode, $intent);

        if ($newlyPaid->isEmpty()) {
            $existingPaid = Order::where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'paid')
                ->count();

            if ($existingPaid > 0 && $paymentService->getPendingCheckout($referenceCode) === null) {
                $paymentService->creditCapturedCardWhenAlreadySettled($referenceCode, $intent);
                Log::info('Order PI payment already finalized (idempotent webhook)', [
                    'reference_code' => $referenceCode,
                ]);

                return;
            }

            $newlyPaid = $paymentService->finalizeStripeFirstCheckout($referenceCode, $intent);

            if ($newlyPaid->isEmpty()) {
                $credited = $paymentService->walletCreditForUnfulfillableCardCheckout($referenceCode);
                if ($credited > 0) {
                    Log::warning('PaymentIntent webhook settled without catalog-visible lines', [
                        'reference_code' => $referenceCode,
                        'wallet_credit' => $credited,
                    ]);

                    return;
                }

                throw new \RuntimeException('No pending card orders or checkout package found for PaymentIntent ref '.$referenceCode);
            }
        }

        $paymentService->notifyPublishersOfPaidOrders($newlyPaid);

        Log::info('Order payment completed via payment_intent.succeeded', [
            'reference_code' => $referenceCode,
            'orders_updated' => $newlyPaid->count(),
        ]);
    }

    private function handleSiteFeatureSession(object $session): void
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $siteId = isset($metadata['site_id']) ? (int) $metadata['site_id'] : 0;
        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : 0;
        $sessionId = (string) ($session->id ?? '');

        if ($siteId <= 0 || $userId <= 0 || $sessionId === '') {
            throw new \RuntimeException('Invalid site_feature session metadata');
        }

        $paymentStatus = $session->payment_status ?? null;
        if ($paymentStatus !== 'paid') {
            throw new \RuntimeException('site_feature session not paid: '.($paymentStatus ?? 'missing'));
        }

        $site = Site::find($siteId);
        $user = User::find($userId);
        if (! $site || ! $user) {
            throw new \RuntimeException('site_feature site/user not found');
        }

        $promotions = app(SitePromotionService::class);
        $promotions->assertStripeChargeMatchesFeaturePrice($session);

        if ((int) $site->publisher_id !== (int) $user->id) {
            $result = $promotions->creditPayerWhenFeatureCannotApply($site, $user, $sessionId);
            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException($result['message'] ?? 'site_feature publisher mismatch');
            }

            Log::warning('site_feature publisher mismatch; credited payer wallet', [
                'site_id' => $siteId,
                'payer_id' => $userId,
                'owner_id' => $site->publisher_id,
                'session_id' => $sessionId,
                'already' => $result['already'] ?? false,
            ]);

            return;
        }

        $result = $promotions->featureFromStripePayment($site, $user, $sessionId);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Failed to apply site feature from webhook');
        }

        if ($result['credited'] ?? false) {
            Log::warning('site_feature listing not catalog-visible; credited payer wallet', [
                'site_id' => $siteId,
                'session_id' => $sessionId,
            ]);

            return;
        }

        Log::info('Site feature applied via webhook', [
            'site_id' => $siteId,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metaArray(mixed $metadata): array
    {
        if ($metadata === null) {
            return [];
        }
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_object($metadata) && method_exists($metadata, 'toArray')) {
            return $metadata->toArray();
        }

        return (array) json_decode(json_encode($metadata), true);
    }

    private function findWebhookLog(string $eventId): ?StripeWebhookLog
    {
        if (! StripeWebhookLog::tableAvailable()) {
            return null;
        }

        try {
            return StripeWebhookLog::where('event_id', $eventId)->first();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordWebhookLog(string $eventId, string $eventType, array $payload): void
    {
        if (! StripeWebhookLog::tableAvailable()) {
            return;
        }

        try {
            StripeWebhookLog::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'processed' => false,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function markWebhookLogProcessed(string $eventId): void
    {
        if (! StripeWebhookLog::tableAvailable()) {
            return;
        }

        try {
            StripeWebhookLog::where('event_id', $eventId)->update(['processed' => true]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
