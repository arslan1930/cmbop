<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Advertiser Order Details modal: payload flags, honest empty copy, and
 * reconstructed activity when order_activities is empty.
 */
final class AdvertiserOrderDetails
{
    public static function expectsLineItems(Order $order): bool
    {
        if ((string) $order->status === 'completed') {
            return true;
        }

        return in_array((string) $order->payment_status, ['paid', 'completed', 'refunded'], true);
    }

    public static function placementsMissing(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->items->isEmpty() && self::expectsLineItems($order);
    }

    public static function emptyItemsMessage(Order $order): string
    {
        if (self::placementsMissing($order)) {
            $number = filled($order->order_number) ? (string) $order->order_number : '#'.$order->id;

            return 'This order has no line items on file, so there is no live URL to show. If you expected a placement here, contact support and mention order '.$number.'.';
        }

        return 'No placements on this order.';
    }

    public static function policyNote(Order $order): string
    {
        $status = (string) $order->status;
        $payment = (string) $order->payment_status;

        if ($status === 'completed') {
            if (self::placementsMissing($order) || ! self::hasLiveUrl($order)) {
                return '';
            }

            return 'If a published link is later removed, use Report link removed.';
        }

        if ($status === 'cancelled' || in_array($payment, ['refunded', 'failed'], true)) {
            return '';
        }

        return 'Declines refund automatically · request changes before auto-approve';
    }

    /**
     * Completed (including clawback) stays open. Leftover refunds and failed
     * charges are not “pay to unlock chat”.
     */
    public static function canSendOrderChat(Order $order): bool
    {
        if ((string) $order->status === 'cancelled') {
            return false;
        }

        if ((string) $order->status === 'completed') {
            return true;
        }

        return (string) $order->payment_status === 'paid';
    }

    public static function orderChatComposerNote(Order $order): ?string
    {
        if ((string) $order->status === 'cancelled') {
            return 'This order is cancelled. Chat is read-only.';
        }

        if ((string) $order->payment_status === 'refunded' && (string) $order->status !== 'completed') {
            return 'This order was refunded. Chat is read-only.';
        }

        if (! self::canSendOrderChat($order)) {
            return 'Chat is available after the order is paid.';
        }

        if ((string) $order->status === 'completed') {
            return self::placementsMissing($order)
                ? 'This order is completed. You can still message support about it.'
                : 'This order is completed. You can still message about this placement.';
        }

        return null;
    }

    public static function orderChatSendBlockedMessage(Order $order): string
    {
        if ((string) $order->status === 'cancelled') {
            return 'This order is cancelled. Chat is closed.';
        }

        if ((string) $order->payment_status === 'refunded') {
            return 'This order was refunded. Chat is closed.';
        }

        return 'Chat is available after the order is paid.';
    }

    public static function unpaidActionMessage(Order $order, string $verb): string
    {
        if ((string) $order->payment_status === 'refunded') {
            return "This order was refunded and cannot be {$verb}.";
        }

        return "This order cannot be {$verb} because payment is not complete.";
    }

    public static function hasLiveUrl(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(fn ($line) => $line instanceof OrderItem && filled($line->live_url));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function presentItems(Order $order): array
    {
        return $order->items
            ->filter(fn ($line) => $line instanceof OrderItem)
            ->map(fn (OrderItem $line) => self::presentItem($line))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentItem(OrderItem $line): array
    {
        $site = $line->relationLoaded('site') ? $line->site : null;
        $line->unsetRelation('site');
        $line->unsetRelation('latestDispute');
        $row = $line->toArray();
        unset($row['site'], $row['latest_dispute'], $row['latestDispute']);

        $row['id'] = $line->id;
        $row['site_id'] = $line->site_id;
        $row['site_name'] = $line->site_name ?: $site?->site_name;
        $row['site_url'] = $line->site_url ?: $site?->site_url;
        $row['visit_url'] = $line->getAttribute('visit_url');
        $row['live_url'] = $line->live_url;
        $row['live_url_submitted_at'] = self::iso($line->live_url_submitted_at);
        $row['live_url_checked_at'] = self::iso($line->live_url_checked_at);
        $row['live_url_http_status'] = $line->live_url_http_status;
        $row['live_url_check_ok'] = $line->live_url_check_ok;
        $row['content_link'] = $line->content_link;
        $row['content_original_name'] = $line->content_original_name;
        $row['content_submission_id'] = $line->content_submission_id;
        $row['content_revision_requested'] = $line->content_revision_requested;
        $row['content_revision_reason'] = $line->content_revision_reason;
        $row['anchor_text'] = $line->anchor_text;
        $row['target_url'] = $line->target_url;
        $row['feature_image_url'] = $line->feature_image_url;
        $row['price'] = $line->price;
        $row['additional_price'] = $line->additional_price;
        $row['homepage_price'] = $line->homepage_price;
        $row['homepage_days'] = $line->homepage_days;
        $row['sensitive_type'] = $line->sensitive_type;
        $row['social_channels'] = $line->social_channels;
        $row['social_post_urls'] = $line->social_post_urls;
        $row['can_report_link_removed'] = (bool) $line->getAttribute('can_report_link_removed');
        $row['dispute_status'] = $line->getAttribute('dispute_status');
        $row['dispute_id'] = $line->getAttribute('dispute_id');
        $row['moderation_status'] = $line->moderation_status;
        $row['modification_requested'] = $line->modification_requested;
        $row['completion_notes'] = $line->completion_notes;
        $row['accepted_at'] = self::iso($line->accepted_at);
        $row['completed_at'] = self::iso($line->completed_at);
        if (isset($line->auto_approve_hours_remaining)) {
            $row['auto_approve_hours_remaining'] = (int) $line->auto_approve_hours_remaining;
        }

        return $row;
    }

    /**
     * When order_activities is empty, synthesise events from order/item dates.
     *
     * @return list<array<string, mixed>>
     */
    public static function reconstructedActivities(Order $order): array
    {
        $order->loadMissing('items');
        $events = [];

        $push = function (mixed $when, string $title, string $event) use (&$events, $order): void {
            $dt = self::asDate($when);
            if (! $dt) {
                return;
            }

            $events[] = [
                'id' => null,
                'order_id' => $order->id,
                'event' => $event,
                'title' => $title,
                'description' => 'Reconstructed from order dates.',
                'icon' => null,
                'badge_color' => 'secondary',
                'actor_name' => null,
                'actor_role' => null,
                'meta' => ['reconstructed' => true],
                'created_at' => $dt->toIso8601String(),
                'relative_time' => $dt->diffForHumans(),
                'exact_time' => $dt->format('M j, Y g:i A'),
            ];
        };

        if ((string) $order->payment_status !== 'failed') {
            $push($order->paid_at, 'Paid', 'reconstructed_paid');
        }

        $liveAt = $order->items
            ->map(fn ($line) => $line instanceof OrderItem ? $line->live_url_submitted_at : null)
            ->filter()
            ->sort()
            ->first();
        $push($liveAt, 'Live URL submitted', 'reconstructed_live_url');

        if ((string) $order->payment_status === 'failed') {
            $push($order->updated_at, 'Payment failed', 'reconstructed_payment_failed');
        } elseif ((string) $order->payment_status === 'refunded' && (string) $order->status !== 'completed') {
            $push($order->updated_at, 'Refunded', 'reconstructed_refunded');
        }

        $push(
            $order->completed_at,
            (string) $order->payment_status === 'refunded' ? 'Completed · refunded' : 'Completed',
            'reconstructed_completed'
        );

        if ($events === []) {
            $push($order->updated_at, 'Last updated', 'reconstructed_updated');
        }

        usort($events, function (array $a, array $b): int {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        return $events;
    }

    private static function iso(mixed $when): ?string
    {
        return self::asDate($when)?->toIso8601String();
    }

    private static function asDate(mixed $when): ?CarbonInterface
    {
        if ($when instanceof CarbonInterface) {
            return $when;
        }
        if ($when === null || $when === '') {
            return null;
        }

        try {
            return Carbon::parse($when);
        } catch (\Throwable) {
            return null;
        }
    }
}
