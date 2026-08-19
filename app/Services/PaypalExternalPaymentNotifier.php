<?php

namespace App\Services;

use App\Mail\PaypalExternalPaymentNotice;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaypalExternalPaymentNotifier
{
    /**
     * @param  Collection<int, Order>  $orders
     * @param  array{refund_id?: string, capture_id?: string, paypal_order_id?: string, amount?: float}  $refunded
     */
    public function notifyAfterCaptureRefund(Collection $orders, array $refunded, string $kind = PaypalExternalPaymentNotice::KIND_COMPLETED_REFUND): void
    {
        $orders = $orders->filter();
        if ($orders->isEmpty()) {
            return;
        }

        $amount = round((float) ($refunded['amount'] ?? 0), 2);
        $eventKey = trim((string) ($refunded['refund_id'] ?? $refunded['capture_id'] ?? ''));
        $paidRemaining = $orders->filter(fn (Order $order) => ($order->payment_status ?? '') === 'paid');
        $completedRefunded = $orders->filter(fn (Order $order) => ($order->status ?? '') === 'completed'
            && ($order->payment_status ?? '') === 'refunded');
        if ($paidRemaining->isEmpty() && $completedRefunded->isEmpty()) {
            return;
        }

        $completedPaid = $paidRemaining->filter(fn (Order $order) => ($order->status ?? '') === 'completed');
        $paidTotal = round((float) $paidRemaining->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $partial = $paidRemaining->isNotEmpty()
            && $amount >= 0.01
            && ($paidTotal - $amount) > 0.01;

        if ($partial) {
            $kind = PaypalExternalPaymentNotice::KIND_PARTIAL_REFUND;
        } elseif ($kind === PaypalExternalPaymentNotice::KIND_COMPLETED_REFUND
            && $completedPaid->isEmpty()
            && $completedRefunded->isEmpty()) {
            return;
        }

        $focus = $completedRefunded->first()
            ?: $completedPaid->first()
            ?: $paidRemaining->first();
        if (! $focus) {
            return;
        }

        $this->notifyOrderParties($focus, $kind, $amount, $eventKey);
    }

    public function notifyDispute(Order $order, string $kind, string $eventKey, float $amount = 0.0): void
    {
        $this->notifyOrderParties($order, $kind, $amount, $eventKey);
    }

    private function notifyOrderParties(Order $order, string $kind, float $amount, string $eventKey): void
    {
        $order->loadMissing(['user', 'items']);
        $advertiser = $order->user;
        if ($advertiser?->email) {
            $this->sendNotice(
                $advertiser,
                PaypalExternalPaymentNotice::AUDIENCE_ADVERTISER,
                $kind,
                $order,
                $amount,
                $eventKey
            );
        }

        $publisherIds = [];
        foreach ($order->items as $item) {
            $siteId = (int) ($item->site_id ?? 0);
            if ($siteId < 1) {
                continue;
            }
            $publisherId = (int) (Site::query()->whereKey($siteId)->value('publisher_id') ?? 0);
            if ($publisherId > 0) {
                $publisherIds[$publisherId] = true;
            }
        }

        foreach (array_keys($publisherIds) as $publisherId) {
            $publisher = User::query()->find($publisherId);
            if ($publisher?->email) {
                $this->sendNotice(
                    $publisher,
                    PaypalExternalPaymentNotice::AUDIENCE_PUBLISHER,
                    $kind,
                    $order,
                    $amount,
                    $eventKey
                );
            }
        }

        try {
            app(InAppNotificationService::class)->notifyAdminsPaypalExternalPayment($order, $kind, $amount);
        } catch (\Throwable $e) {
            Log::warning('PayPal external payment admin bell failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendNotice(
        User $user,
        string $audience,
        string $kind,
        Order $order,
        float $amount,
        string $eventKey
    ): void {
        $gate = 'paypal_external:'.$kind.':'.$order->id.':'.$eventKey.':'.$user->id;
        try {
            if ($eventKey !== '' && ! Cache::add($gate, 1, now()->addHours(6))) {
                return;
            }
        } catch (\Throwable) {
        }

        try {
            Mail::to($user->email)->send(new PaypalExternalPaymentNotice(
                $user,
                $audience,
                $kind,
                (string) ($order->reference_code ?? ''),
                $amount,
                $order,
                $eventKey
            ));
        } catch (\Throwable $e) {
            Log::error('PayPal external payment notice failed', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(InAppNotificationService::class)->notifyPaypalExternalPayment(
                $user,
                $audience,
                $kind,
                $order,
                $amount
            );
        } catch (\Throwable $e) {
            Log::warning('PayPal external payment bell failed', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
