<?php

namespace App\Services\Orders;

use App\Models\Order;

/**
 * Which payment status moves staff may take from Order Payments / order show.
 *
 * Money math stays in PaymentController / OrderRefundService. This only
 * answers "is this transition offered?" so the order page and the payments
 * dropdown cannot disagree.
 */
class AdminPaymentStatusPolicy
{
    /**
     * @return list<string>
     */
    public function allowedStatuses(Order $order): array
    {
        $current = (string) $order->payment_status;

        if ($current === 'refunded') {
            return [];
        }

        if ($current === 'paid') {
            if (in_array((string) $order->status, ['completed'], true)) {
                return [];
            }

            // Keep `paid` so staff can save notes / transfer reference
            // without a money move.
            return ['paid', 'failed', 'refunded'];
        }

        // Paid→failed already credits captured methods. Allow Refunded as a
        // bookkeeping correction (no second credit) and Failed for notes.
        if ($current === 'failed' && (string) $order->status === 'cancelled') {
            return ['failed', 'refunded'];
        }

        $allowed = ['pending', 'paid', 'failed'];
        if (in_array((string) $order->status, ['cancelled', 'completed'], true)) {
            $allowed = array_values(array_diff($allowed, ['paid']));
        }

        return $allowed;
    }

    public function canRefundInFlight(Order $order): bool
    {
        return $order->payment_status === 'paid'
            && in_array('refunded', $this->allowedStatuses($order), true);
    }

    public function canFailInFlight(Order $order): bool
    {
        return $order->payment_status === 'paid'
            && in_array('failed', $this->allowedStatuses($order), true);
    }

    public function needsDisputeClawback(Order $order): bool
    {
        return $order->payment_status === 'paid'
            && (string) $order->status === 'completed';
    }

    public function moneyHint(string $status, string $method, ?string $current): string
    {
        if ($current !== null && $status === $current) {
            return 'Saves notes and transfer reference. Payment status stays '.$status.'.';
        }
        if ($status === 'refunded') {
            if ($current === 'failed') {
                return 'Relabels this failed payment as refunded. Funds were already returned when it was marked failed — this does not credit the wallet again.';
            }
            if ($method === 'paypal') {
                return 'Refunds the PayPal capture back to the buyer. It does not credit the advertiser wallet again. Completed placements must use a dispute clawback.';
            }
            if ($method === 'card') {
                return 'Refund credits the advertiser wallet. It does not refund the Stripe charge. Completed placements must use a dispute clawback.';
            }

            return 'Refund returns funds to the advertiser wallet and cancels the order. Completed placements must use a dispute clawback.';
        }
        if ($status === 'failed') {
            if ($method === 'wallet') {
                return 'Failed cancels an in-flight order and releases the wallet hold. Completed orders cannot be failed here.';
            }
            if ($method === 'paypal') {
                return 'Failed cancels an in-flight order and refunds the PayPal capture back to the buyer (same money move as Refunded). It does not credit the advertiser wallet. Completed orders cannot be failed here.';
            }

            return 'Failed cancels an in-flight order and credits the advertiser wallet for a settled card / bank / Wise / crypto payment (same money move as Refunded). It does not refund the Stripe charge. Completed orders cannot be failed here.';
        }
        if ($status === 'paid') {
            return 'Mark paid only after the transfer is on the statement. Publishers are notified even if customer mail is off.';
        }

        return '';
    }

    public function disallowedMessage(Order $order, string $newStatus): string
    {
        if ($order->payment_status === 'paid' && $order->status === 'completed') {
            if ($newStatus === 'refunded') {
                return 'Completed orders cannot be refunded here. Use a dispute clawback so the publisher payout is reversed first.';
            }
            if ($newStatus === 'failed') {
                return 'Completed orders cannot be marked failed here. Use a dispute clawback so the publisher payout is reversed first.';
            }

            return 'Completed orders cannot be changed here. Use a dispute clawback so the publisher payout is reversed first.';
        }

        if ($order->payment_status === 'paid' && $newStatus === 'pending') {
            return 'A paid payment cannot be moved back to pending. Mark it failed or refunded instead.';
        }

        if ($newStatus === 'paid') {
            return 'This order cannot be marked paid. Cancelled, completed, or refunded payments have to stay settled.';
        }

        return 'That payment status change is not allowed for this order.';
    }
}
