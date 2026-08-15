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
        $paymentType = isset($metadata['type']) ? (string) $metadata['type'] : null;
        $paymentType = $paymentType === '' ? null : $paymentType;

        // Webhook snapshots can omit type. Re-read the live session before
        // marking the event processed with no credit.
        if ($paymentType === null) {
            $session = $this->refreshUntypedCheckoutSession($session);
            $metadata = $this->metaArray($session->metadata ?? null);
            $paymentType = isset($metadata['type']) ? (string) $metadata['type'] : null;
            $paymentType = $paymentType === '' ? null : $paymentType;
        }

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
                if ($this->detectWalletHintsOnUntypedSession($session, $metadata)) {
                    break;
                }

                $this->recoverUntypedCheckoutSessionFromPaymentIntent($session);
                break;
        }
    }

    private function routePaymentIntentSucceeded(object $intent): void
    {
        $metadata = $this->metaArray($intent->metadata ?? null);
        $paymentType = isset($metadata['type']) ? (string) $metadata['type'] : null;
        $paymentType = $paymentType === '' ? null : $paymentType;

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
                // Add Funds copies session_reference (deposit_{uniqid}) onto the
                // PaymentIntent. If Stripe omitted type but kept that key,
                // this is still a paid wallet top-up — do not mark processed
                // and swallow it.
                if (WalletStripeDepositService::isAddFundsSessionReference($metadata['session_reference'] ?? '')) {
                    app(WalletStripeDepositService::class)->creditFromPaymentIntentObject($intent);
                    break;
                }

                $this->recoverUntypedPaymentIntentFromCheckoutSession($intent);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function detectWalletHintsOnUntypedSession(object $session, array $metadata): bool
    {
        if (isset($metadata['deposit_id'])) {
            Log::info('Detected deposit payment by deposit_id field');
            $this->handleWalletDepositSession($session);

            return true;
        }

        if (WalletStripeDepositService::isAddFundsSessionReference($metadata['session_reference'] ?? '')) {
            Log::info('Detected wallet deposit by session_reference field');
            $this->handleWalletDepositSession($session);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function detectPaymentTypeByMetadata(object $session, array $metadata): void
    {
        if ($this->detectWalletHintsOnUntypedSession($session, $metadata)) {
            return;
        }

        $this->recoverUntypedCheckoutSessionFromPaymentIntent($session);
    }

    private function refreshUntypedCheckoutSession(object $session): object
    {
        $sessionId = (string) ($session->id ?? '');
        $fresh = app(WalletStripeDepositService::class)->refreshCheckoutSession($sessionId);

        return is_object($fresh) ? $fresh : $session;
    }

    /**
     * Session metadata can be empty while payment_intent_data still has type.
     * A Stripe retrieve failure must 500 so the event is retried.
     */
    private function recoverUntypedCheckoutSessionFromPaymentIntent(object $session): void
    {
        $deposits = app(WalletStripeDepositService::class);
        $paymentIntentId = $deposits->paymentIntentIdFromStripeObject($session);
        if ($paymentIntentId === '') {
            // A paid session should get a PaymentIntent. Marking processed
            // here swallowed the charge when the later PI webhook was also
            // untyped. Tests without a Stripe secret still ignore.
            if (($session->payment_status ?? null) === 'paid'
                && trim((string) config('services.stripe.secret', '')) !== '') {
                throw new \RuntimeException('Untyped paid Checkout Session has no PaymentIntent yet');
            }

            Log::warning('Unable to determine payment type', [
                'session_id' => $session->id ?? null,
            ]);

            return;
        }

        $intent = $deposits->fetchPaymentIntent($paymentIntentId);
        if (! $intent) {
            Log::warning('Ignoring untyped checkout session; PaymentIntent not available', [
                'session_id' => $session->id ?? null,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $piMetadata = $this->metaArray($intent->metadata ?? null);
        $sessionMetadata = $this->metaArray($session->metadata ?? null);
        $metadata = array_merge($sessionMetadata, array_filter(
            $piMetadata,
            static fn ($value) => $value !== null && $value !== ''
        ));
        $paymentType = isset($metadata['type']) ? (string) $metadata['type'] : null;
        $paymentType = $paymentType === '' ? null : $paymentType;
        $overlayed = $deposits->checkoutSessionWithOverlayedMetadata($session, $piMetadata, $paymentIntentId);

        Log::info('Recovered untyped Checkout Session from PaymentIntent', [
            'session_id' => $session->id ?? null,
            'payment_intent_id' => $paymentIntentId,
            'payment_type' => $paymentType,
        ]);

        switch ($paymentType) {
            case 'wallet_deposit':
            case 'deposit':
                $deposits->creditFromCheckoutSession($overlayed);

                return;

            case 'order_payment':
            case 'order':
                $this->handleOrderPaymentIntent($intent, $metadata);

                return;

            case 'site_feature':
                $this->handleSiteFeatureSession($overlayed);

                return;

            default:
                if (WalletStripeDepositService::isAddFundsSessionReference($metadata['session_reference'] ?? '')) {
                    $deposits->creditFromCheckoutSession($overlayed);

                    return;
                }

                Log::warning('Ignoring untyped checkout session after PaymentIntent recover', [
                    'session_id' => $session->id ?? null,
                    'payment_intent_id' => $paymentIntentId,
                ]);
        }
    }

    private function handleWalletDepositSession(object $session): void
    {
        app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);
    }

    /**
     * payment_intent.succeeded can arrive with empty metadata when Stripe
     * did not copy payment_intent_data. The Checkout Session still has type.
     * A Stripe API failure must 500 so the event is retried, not marked processed.
     */
    private function recoverUntypedPaymentIntentFromCheckoutSession(object $intent): void
    {
        $paymentIntentId = (string) ($intent->id ?? '');
        if ($paymentIntentId === '') {
            return;
        }

        $deposits = app(WalletStripeDepositService::class);
        $session = $deposits->fetchCheckoutSessionForPaymentIntent($paymentIntentId);
        if (! $session) {
            // Session::all can be empty for a moment after PaymentIntent
            // succeeds. Marking processed here swallowed the paid top-up.
            // Without a Stripe secret (tests) there is nothing to retry.
            if (trim((string) config('services.stripe.secret', '')) !== '') {
                throw new \RuntimeException('Untyped PaymentIntent has no Checkout Session yet');
            }

            Log::info('Ignoring payment_intent.succeeded without known type', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $metadata = $this->metaArray($session->metadata ?? null);
        $paymentType = isset($metadata['type']) ? (string) $metadata['type'] : null;
        $paymentType = $paymentType === '' ? null : $paymentType;
        $paidSession = $deposits->checkoutSessionWithPaidPaymentIntent($session, $paymentIntentId);

        Log::info('Recovered untyped PaymentIntent from Checkout Session', [
            'payment_intent_id' => $paymentIntentId,
            'session_id' => $session->id ?? null,
            'payment_type' => $paymentType,
        ]);

        switch ($paymentType) {
            case 'wallet_deposit':
            case 'deposit':
                $deposits->creditFromRecoveredCheckoutSession($session, $paymentIntentId);

                return;

            case 'order_payment':
            case 'order':
                $this->handleOrderPaymentIntent($intent, $metadata);

                return;

            case 'site_feature':
                $this->handleSiteFeatureSession($paidSession);

                return;

            default:
                $this->detectPaymentTypeByMetadata($paidSession, $metadata);
        }
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

            if ($existingPaid > 0) {
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
}
