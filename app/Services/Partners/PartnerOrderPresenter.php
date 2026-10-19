<?php

namespace App\Services\Partners;

use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\User;
use App\Support\PartnerDashboard;
use Illuminate\Support\Facades\Schema;

class PartnerOrderPresenter
{
    public function buyerRef(User $partnerUser, OrderItem $item): string
    {
        $advertiserId = (int) ($item->order?->user_id ?? 0);
        $raw = hash('sha256', $advertiserId.'|'.$partnerUser->id.'|'.(string) config('app.key'));

        return 'B-'.strtoupper(substr($raw, 0, 4));
    }

    /**
     * Allow-list partner order payload. Never includes advertiser identity or SLB price.
     *
     * @return array<string, mixed>
     */
    public function present(User $partnerUser, OrderItem $item): array
    {
        $item->loadMissing(['order', 'site']);
        $status = PartnerOrderStatus::fromItem($item);
        $payout = $item->publisherPayoutAmount();
        $currency = $item->publisherPayoutCurrency();
        $site = $item->site;

        return [
            'id' => (int) $item->id,
            'order_number' => (string) ($item->order?->order_number ?? $item->id),
            'status' => $status,
            'status_label' => PartnerOrderStatus::label($status),
            'site_name' => (string) ($item->site_name ?: $site?->site_name),
            'site_domain' => (string) ($site?->domain ?: $item->site_url),
            'your_payout' => $payout,
            'your_payout_formatted' => $this->money($payout, $currency),
            'currency' => $currency,
            'buyer_ref' => $this->buyerRef($partnerUser, $item),
            'anchor_text' => (string) ($item->anchor_text ?: $item->briefAnchorText()),
            'target_url' => (string) ($item->target_url ?: $item->briefTargetUrl()),
            'content_link' => (string) ($item->publisherContentLink() ?? ''),
            'content_download_url' => (int) ($item->content_submission_id ?? 0) > 0
                ? PartnerDashboard::route('orders.content', ['order' => $item->id], false)
                : '',
            'live_url' => (string) ($item->live_url ?? ''),
            'live_url_check_ok' => $item->live_url_check_ok,
            'live_url_http_status' => $item->live_url_http_status,
            'correction_reason' => $item->isModificationRequested() ? (string) ($item->completion_notes ?? '') : '',
            'content_revision_requested' => $item->isContentRevisionRequested(),
            'content_revision_reason' => $item->isContentRevisionRequested() ? (string) ($item->content_revision_reason ?? '') : '',
            'created_at' => optional($item->created_at)?->toDayDateTimeString(),
            'accepted_at' => optional($item->accepted_at)?->toDayDateTimeString(),
            'live_url_submitted_at' => optional($item->live_url_submitted_at)?->toDayDateTimeString(),
            'can_accept' => $status === PartnerOrderStatus::PENDING,
            'can_reject' => in_array($status, [PartnerOrderStatus::PENDING, PartnerOrderStatus::IN_PROGRESS], true),
            'can_submit' => in_array($status, [PartnerOrderStatus::IN_PROGRESS, PartnerOrderStatus::NEEDS_CORRECTION], true)
                && ! $item->isContentRevisionRequested(),
            'can_resubmit' => $status === PartnerOrderStatus::NEEDS_CORRECTION,
            'timeline' => $this->timeline($item),
        ];
    }

    /**
     * @param  iterable<OrderItem>  $items
     * @return list<array<string, mixed>>
     */
    public function presentMany(User $partnerUser, iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->present($partnerUser, $item);
        }

        return $rows;
    }

    /**
     * @return list<array{at: string, title: string, description: string}>
     */
    public function timeline(OrderItem $item): array
    {
        $events = [];
        if (Schema::hasTable('order_activities') && $item->order_id) {
            $rows = OrderActivity::query()
                ->where('order_id', $item->order_id)
                ->orderBy('id')
                ->get();
            foreach ($rows as $row) {
                $events[] = [
                    'at' => optional($row->created_at)?->toDayDateTimeString() ?: '',
                    'title' => $this->sanitizeTimelineTitle((string) $row->title, (string) $row->actor_role),
                    'description' => $this->sanitizeTimelineText((string) $row->description),
                ];
            }
        }
        if ($events !== []) {
            return $events;
        }

        $order = $item->order;
        if ($order?->paid_at) {
            $events[] = ['at' => $order->paid_at->toDayDateTimeString(), 'title' => 'Order received', 'description' => 'Paid and assigned to your site.'];
        }
        if ($item->accepted_at) {
            $events[] = ['at' => $item->accepted_at->toDayDateTimeString(), 'title' => 'Accepted', 'description' => 'You accepted this order.'];
        }
        if ($item->live_url_submitted_at) {
            $events[] = ['at' => $item->live_url_submitted_at->toDayDateTimeString(), 'title' => 'Live URL submitted', 'description' => ''];
        }
        if ($item->isModificationRequested()) {
            $events[] = ['at' => optional($item->modification_requested_at)?->toDayDateTimeString() ?: '', 'title' => 'Correction requested', 'description' => $this->sanitizeTimelineText((string) ($item->completion_notes ?? ''))];
        }
        if ($order?->status === 'completed' && $order->completed_at) {
            $events[] = ['at' => $order->completed_at->toDayDateTimeString(), 'title' => 'Completed', 'description' => 'Approved. Your payout is available in Money.'];
        }
        if ($order?->status === 'cancelled') {
            $events[] = ['at' => optional($order->updated_at)?->toDayDateTimeString() ?: '', 'title' => 'Rejected', 'description' => $this->sanitizeTimelineText((string) ($item->rejection_reason ?? ''))];
        }

        return $events;
    }

    public function money(float $amount, string $currency = 'EUR'): string
    {
        $symbol = $currency === 'EUR' ? '€' : $currency.' ';

        return $symbol.number_format($amount, 2);
    }

    public function listingStatusLabel(string $status): string
    {
        return match ($status) {
            'live' => 'Live',
            'paused' => 'Paused',
            default => 'Not listed',
        };
    }

    private function sanitizeTimelineTitle(string $title, string $actorRole): string
    {
        $title = $this->sanitizeTimelineText($title);
        if (strtolower($actorRole) === 'advertiser') {
            return 'Update from SEOLinkBuildings';
        }

        return $title;
    }

    private function sanitizeTimelineText(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email removed]', $text) ?? $text;

        return $text;
    }

    public static function partnerIdForUser(User $user): ?int
    {
        return Partner::forUser($user)?->id;
    }
}
