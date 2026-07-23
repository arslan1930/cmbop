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
use App\Services\WalletStripeDepositService;
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

                return response()->json(['error' => 'Webhook not configured'], 500);
            }

            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            $eventType = $event->type;
            $eventId = $event->id;

            Log::info('Processing webhook event', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            // Only skip when a prior delivery fully succeeded.
            $existingLog = StripeWebhookLog::where('event_id', $eventId)->first();
            if ($existingLog && $existingLog->processed) {
                Log::info('Webhook already processed', ['event_id' => $eventId]);

                return response()->json(['status' => 'duplicate'], 200);
            }

            if (! $existingLog) {
                StripeWebhookLog::create([
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => json_encode($event),
                    'processed' => false,
                ]);
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

            StripeWebhookLog::where('event_id', $eventId)->update(['processed' => true]);

            return response()->json(['status' => 'success'], 200);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook error: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
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
            Log::info('Detected order payment by reference_code field');
            $this->handleOrderPaymentSession($session);

            return;
        }

        Log::warning('Unable to determine payment type', ['metadata' => $metadata]);
    }

    private function handleWalletDepositSession(object $session): void
    {
        app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);
    }

    private function handleOrderCheckoutFailed(object $session, string $reason): void
    {
        $metadata = $this->metaArray($session->metadata ?? null);
        $paymentType = $metadata['type'] ?? null;
        $referenceCode = $metadata['reference_code'] ?? null;

        if (! $referenceCode) {
            return;
        }

        if ($paymentType && ! in_array($paymentType, ['order_payment', 'order'], true)) {
            return;
        }

        app(OrderPaymentService::class)->markOrdersFailedFromReference($referenceCode, $reason);
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

        $paymentService = app(OrderPaymentService::class);
        $newlyPaid = $paymentService->markOrdersPaidFromStripeSession($referenceCode, $session);

        if ($newlyPaid->isEmpty()) {
            $existingPaid = Order::where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'paid')
                ->count();

            if ($existingPaid > 0) {
                Log::info('Order payment already finalized (idempotent webhook)', [
                    'reference_code' => $referenceCode,
                    'paid_count' => $existingPaid,
                ]);

                return;
            }

            // Stripe-first checkouts store a cache package and create orders only after pay.
            // Materialize via finalize if the browser never hit the success URL.
            $newlyPaid = $paymentService->finalizeStripeFirstCheckout($referenceCode, $session);

            if ($newlyPaid->isEmpty()) {
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

        $paymentService = app(OrderPaymentService::class);
        $newlyPaid = $paymentService->markOrdersPaidFromPaymentIntent($referenceCode, $intent);

        if ($newlyPaid->isEmpty()) {
            $existingPaid = Order::where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'paid')
                ->count();

            if ($existingPaid > 0) {
                Log::info('Order PI payment already finalized (idempotent webhook)', [
                    'reference_code' => $referenceCode,
                ]);

                return;
            }

            throw new \RuntimeException('No pending card orders for PaymentIntent ref '.$referenceCode);
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
        if ($paymentStatus && $paymentStatus !== 'paid') {
            throw new \RuntimeException('site_feature session not paid: '.$paymentStatus);
        }

        $site = Site::find($siteId);
        $user = User::find($userId);
        if (! $site || ! $user) {
            throw new \RuntimeException('site_feature site/user not found');
        }

        if ((int) $site->publisher_id !== (int) $user->id) {
            throw new \RuntimeException('site_feature publisher mismatch');
        }

        $result = app(SitePromotionService::class)->featureFromStripePayment($site, $user, $sessionId);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Failed to apply site feature from webhook');
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
}
