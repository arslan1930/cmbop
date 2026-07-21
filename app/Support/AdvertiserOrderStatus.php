<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Shared advertiser-facing order status labels and next-action copy.
 */
class AdvertiserOrderStatus
{
    /**
     * @return array{label: string, next: string, cls: string, stage: string, auto_approve_hint: ?string}
     */
    public static function meta(Order $order, ?OrderItem $item = null): array
    {
        $item = $item ?? $order->items->first();
        $hasLiveUrl = $item && filled($item->live_url);
        $modRequested = $item && method_exists($item, 'isModificationRequested')
            ? $item->isModificationRequested()
            : (($item->modification_requested ?? 'no') === 'yes');
        $payment = (string) $order->payment_status;
        $status = (string) $order->status;

        $autoHint = null;
        if ($status === 'review' && $hasLiveUrl && $item && ! $modRequested) {
            $hours = (int) $item->getAutoApproveHoursRemaining();
            if ($hours > 0) {
                $autoHint = $hours >= 24
                    ? 'Auto-approves in about '.ceil($hours / 24).' day(s) if you take no action'
                    : 'Auto-approves in about '.$hours.' hour(s) if you take no action';
            } else {
                $autoHint = 'Ready for auto-approve — approve now or request changes';
            }
        }

        if ($status === 'cancelled' && $payment === 'refunded') {
            return [
                'label' => 'Cancelled · refunded',
                'next' => 'Refunded to your wallet (usually instant). No further action needed.',
                'cls' => 'status-cancelled',
                'stage' => 'refunded',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'cancelled') {
            return [
                'label' => 'Cancelled',
                'next' => 'No further action needed.',
                'cls' => 'status-cancelled',
                'stage' => 'cancelled',
                'auto_approve_hint' => null,
            ];
        }

        if ($payment === 'failed') {
            return [
                'label' => 'Payment failed',
                'next' => 'Pay again from Orders, or choose another payment method.',
                'cls' => 'status-cancelled',
                'stage' => 'payment_failed',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'pending' && $payment !== 'paid') {
            return [
                'label' => 'Awaiting payment',
                'next' => 'Complete payment so the publisher can start.',
                'cls' => 'status-pending',
                'stage' => 'awaiting_payment',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'pending' && $payment === 'paid') {
            return [
                'label' => 'Paid · waiting for publisher',
                'next' => 'Publisher will accept the order and start working.',
                'cls' => 'status-pending',
                'stage' => 'paid',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'processing' && $modRequested) {
            return [
                'label' => 'Revision requested',
                'next' => 'Waiting on the publisher to update the post and resubmit the live URL.',
                'cls' => 'status-processing',
                'stage' => 'revision',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'processing') {
            $accepted = $item && ! empty($item->accepted_at);

            return [
                'label' => $accepted ? 'Accepted · processing' : 'Processing',
                'next' => 'Publisher is preparing and publishing your content, then will send a live URL.',
                'cls' => 'status-processing',
                'stage' => 'processing',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'review') {
            return [
                'label' => 'URL delivered · your review',
                'next' => $hasLiveUrl
                    ? 'Check the live URL, then approve or request changes.'
                    : 'Waiting for live URL.',
                'cls' => 'status-review',
                'stage' => 'url_delivered',
                'auto_approve_hint' => $autoHint,
            ];
        }

        if ($status === 'completed') {
            return [
                'label' => 'Completed',
                'next' => 'All done — the publisher has been paid for this placement.',
                'cls' => 'status-completed',
                'stage' => 'completed',
                'auto_approve_hint' => null,
            ];
        }

        return [
            'label' => ucfirst($status),
            'next' => '',
            'cls' => 'status-pending',
            'stage' => $status,
            'auto_approve_hint' => null,
        ];
    }

    /**
     * @return list<array{label: string, done: bool, current: bool}>
     */
    public static function timelineSteps(Order $order, ?OrderItem $item = null): array
    {
        $item = $item ?? $order->items->first();
        $status = (string) $order->status;
        $paid = in_array($order->payment_status, ['paid', 'completed', 'refunded'], true)
            || in_array($status, ['processing', 'review', 'completed'], true);
        $acceptedOrLater = in_array($status, ['processing', 'review', 'completed'], true)
            || ($item && ! empty($item->accepted_at));
        $urlDelivered = $status === 'review' || $status === 'completed'
            || ($item && filled($item->live_url) && in_array($status, ['review', 'completed'], true));
        $completed = $status === 'completed';
        $modRequested = $item && (($item->modification_requested ?? 'no') === 'yes');

        $steps = [
            ['label' => 'Paid', 'done' => $paid, 'current' => false],
            ['label' => 'Accepted', 'done' => $acceptedOrLater, 'current' => false],
            ['label' => 'Processing', 'done' => $urlDelivered || $completed, 'current' => false],
            ['label' => 'URL delivered', 'done' => $completed, 'current' => false],
            ['label' => 'Completed', 'done' => $completed, 'current' => false],
        ];

        if ($status === 'cancelled') {
            return $steps;
        }

        if ($status === 'pending' && ! $paid) {
            $steps[0]['current'] = true;
            $steps[0]['done'] = false;
        } elseif ($status === 'pending' && $paid) {
            $steps[1]['current'] = true;
        } elseif ($status === 'processing' && $modRequested) {
            $steps[2]['current'] = true;
            $steps[2]['label'] = 'Revision';
            $steps[2]['done'] = false;
            $steps[3]['done'] = false;
        } elseif ($status === 'processing') {
            $steps[2]['current'] = true;
        } elseif ($status === 'review') {
            $steps[3]['current'] = true;
            $steps[3]['done'] = false;
        } elseif ($status === 'completed') {
            $steps[4]['current'] = true;
        }

        return $steps;
    }
}
