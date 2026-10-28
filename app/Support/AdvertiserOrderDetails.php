<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

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

    public static function adminMissingPlacementsNote(Order $order): string
    {
        $number = filled($order->order_number) ? (string) $order->order_number : '#'.$order->id;

        return 'Paid/completed orders normally have placements that name the publisher and stamp completion. '
            .'Order '.$number.' has none — typically a legacy import, a status change made without checkout, or placements that were deleted. '
            .'Publisher and live URL cannot be reconstructed from the order row alone.';
    }

    /**
     * @return array{at: ?CarbonInterface, inferred: bool, note: ?string}
     */
    public static function completedAtDisplay(Order $order): array
    {
        if ($order->completed_at) {
            return ['at' => $order->completed_at, 'inferred' => false, 'note' => null];
        }

        $order->loadMissing('items');
        $itemAt = $order->items
            ->map(fn ($line) => $line instanceof OrderItem ? $line->completed_at : null)
            ->filter()
            ->sort()
            ->last();
        if ($itemAt) {
            return [
                'at' => self::asDate($itemAt),
                'inferred' => true,
                'note' => 'From a placement stamp. orders.completed_at is empty — run database/sql/backfill_orders_completed_at.sql to persist it.',
            ];
        }

        if ((string) $order->status === 'completed') {
            $fallback = $order->paid_at ?: $order->updated_at;

            return [
                'at' => $fallback,
                'inferred' => $fallback !== null,
                'note' => $fallback
                    ? 'Estimated from '.($order->paid_at ? 'paid_at' : 'last update').'. The order was marked completed without a completion stamp.'
                    : 'This order is marked completed but has no completion timestamp.',
            ];
        }

        return ['at' => null, 'inferred' => false, 'note' => null];
    }

    public static function policyNote(Order $order): string
    {
        $status = (string) $order->status;
        $payment = (string) $order->payment_status;

        if ($status === 'completed') {
            if (self::placementsMissing($order) || ! self::hasLiveUrl($order)) {
                return '';
            }

            $note = 'If a published link is later removed, use Report link removed.';
            if ($order->link_guarantee) {
                $note .= ' '.$order->linkGuaranteeTermsNote();
            }

            return $note;
        }

        if ($status === 'cancelled' || $payment === 'refunded' || $payment === 'failed') {
            return '';
        }

        $note = 'Declines refund automatically · request changes before auto-approve';
        if ($order->link_guarantee) {
            $note .= ' · '.$order->linkGuaranteeTermsNote();
        }

        return $note;
    }

    public static function hasLiveUrl(Order $order): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(fn ($line) => $line instanceof OrderItem && filled($line->live_url));
    }

    /**
     * Compact link-term chips for the Order Details tracking card.
     * Only includes values already present on the order / related sites.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function linkTerms(Order $order): array
    {
        $order->loadMissing('items.site');
        $chips = [];

        $linkType = null;
        $publication = null;
        foreach ($order->items as $line) {
            if (! $line instanceof OrderItem) {
                continue;
            }
            $site = $line->relationLoaded('site') ? $line->site : null;
            if (! $linkType && $site && filled($site->link_type)) {
                $linkType = strtolower(trim((string) $site->link_type));
            }
            if (! $publication && $site && filled($site->publication_time)) {
                $publication = trim((string) $site->publication_time);
            }
        }

        if (in_array($linkType, ['dofollow', 'nofollow'], true)) {
            $chips[] = ['key' => 'link_type', 'label' => $linkType];
        } elseif (filled($linkType)) {
            $chips[] = ['key' => 'link_type', 'label' => $linkType];
        }

        if (filled($publication)) {
            $chips[] = ['key' => 'publication_time', 'label' => $publication];
        }

        if ($order->link_guarantee !== null) {
            $chips[] = [
                'key' => 'link_guarantee',
                'label' => $order->link_guarantee ? '12-month guarantee' : 'No 12-month guarantee',
            ];
        }

        return $chips;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function presentItems(Order $order): array
    {
        try {
            $order->loadMissing('items.contentSubmission');
        } catch (\Throwable) {
            // Leftover hosts may not have content_submissions.
        }

        return $order->items
            ->filter(fn ($line) => $line instanceof OrderItem)
            ->map(fn (OrderItem $line) => self::presentItem($line))
            ->values()
            ->all();
    }

    /**
     * Billing & Invoices download URL for this order, or null when none exists.
     */
    public static function invoiceDownloadUrl(Order $order): ?string
    {
        try {
            if (! Schema::hasTable('invoices')) {
                return null;
            }

            $invoice = Invoice::query()
                ->where('user_id', $order->user_id)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->where(function ($q) use ($order) {
                    $q->where('order_id', $order->id);
                    if (filled($order->reference_code)) {
                        $q->orWhere('reference_code', $order->reference_code);
                    }
                    if (filled($order->order_number)) {
                        $q->orWhere('order_number', $order->order_number);
                    }
                })
                ->latest('id')
                ->first();

            if (! $invoice || ! $invoice->advertiserCanDownloadPdf()) {
                return null;
            }

            return route('advertiser.billing.download', $invoice, false);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function presentItem(OrderItem $line): array
    {
        $site = $line->relationLoaded('site') ? $line->site : null;
        if (! $site && $line->site_id) {
            try {
                $site = $line->site;
            } catch (\Throwable) {
                $site = null;
            }
        }
        $submission = $line->relationLoaded('contentSubmission') ? $line->contentSubmission : null;
        $line->unsetRelation('site');
        $line->unsetRelation('latestDispute');
        $line->unsetRelation('contentSubmission');
        $row = $line->toArray();
        unset($row['site'], $row['latest_dispute'], $row['latestDispute'], $row['content_submission'], $row['contentSubmission']);

        $row['id'] = $line->id;
        $row['site_id'] = $line->site_id;
        $row['site_name'] = $line->site_name ?: $site?->site_name;
        $row['site_url'] = $line->site_url ?: $site?->site_url;
        $row['link_type'] = $site?->link_type ? (string) $site->link_type : null;
        $row['publication_time'] = $site?->publication_time ? (string) $site->publication_time : null;
        $row['catalog_url'] = $line->site_id
            ? route('advertiser.catalog', ['site' => $line->site_id], false)
            : null;
        $row['visit_url'] = $line->getAttribute('visit_url');
        $row['live_url'] = $line->live_url;
        $row['live_url_submitted_at'] = self::iso($line->live_url_submitted_at);
        $row['live_url_checked_at'] = self::iso($line->live_url_checked_at);
        $row['live_url_http_status'] = $line->live_url_http_status;
        $row['live_url_check_ok'] = $line->live_url_check_ok;
        $row['content_link'] = $line->content_link;
        $row['content_original_name'] = $line->content_original_name ?: $submission?->original_filename;
        $row['content_submission_id'] = $line->content_submission_id;
        $row['word_count'] = $submission && $submission->word_count
            ? (int) $submission->word_count
            : null;
        $row['content_revision_requested'] = $line->content_revision_requested;
        $row['content_revision_reason'] = $line->content_revision_reason;
        $row['anchor_text'] = $line->anchor_text;
        $row['target_url'] = $line->target_url;
        $row['feature_image_url'] = $line->feature_image_url;
        $row['price'] = $line->price;
        $row['content_source'] = $line->content_source ?? 'buyer';
        $row['writing_fee'] = $line->writing_fee;
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

        $push($order->paid_at, 'Paid', 'reconstructed_paid');

        $liveAt = $order->items
            ->map(fn ($line) => $line instanceof OrderItem ? $line->live_url_submitted_at : null)
            ->filter()
            ->sort()
            ->first();
        $push($liveAt, 'Live URL submitted', 'reconstructed_live_url');

        $push($order->completed_at, 'Completed', 'reconstructed_completed');

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
