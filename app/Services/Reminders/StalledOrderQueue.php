<?php

namespace App\Services\Reminders;

use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Orders the automated cadence could not rescue.
 *
 * The last stage of the publisher reminder is "a person needs to decide", and
 * that decision is almost always the same one — chase once more, or refund the
 * advertiser so they can spend the money elsewhere. Both need the same facts in
 * front of them, so they are gathered here rather than in the dashboard.
 */
class StalledOrderQueue
{
    public function __construct(private OrderDeadline $deadlines) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function items(int $limit = 25): Collection
    {
        try {
            if (! Schema::hasColumn('order_items', 'publish_nudge_stage')) {
                return collect();
            }

            $stalledFrom = (int) config('reminders.publisher_publish.stalled_from_stage', 4);
            $acceptStages = count((array) config('reminders.publisher_accept.stages_hours', [12, 36, 72]));

            $unpublished = OrderItem::query()
                ->whereAcceptedAtIsRecorded()
                ->where(fn ($q) => $q->whereNull('live_url')->orWhere('live_url', ''))
                ->where('publish_nudge_stage', '>=', $stalledFrom)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid')
                        ->whereIn('status', ['processing', 'pending'])
                        ->notAwaitingScheduledRelease();
                })
                ->with(['order.user', 'site.publisher'])
                ->limit($limit)
                ->get()
                ->map(fn (OrderItem $item) => $this->row($item, 'publish'));

            // Unaccepted orders are the worse case — nothing has happened at all —
            // so they are surfaced in the same list once the cadence is exhausted.
            $unaccepted = collect();
            if (Schema::hasColumn('order_items', 'accept_nudge_stage')) {
                $unaccepted = OrderItem::query()
                    ->whereAcceptedAtIsMissing()
                    ->where('accept_nudge_stage', '>=', $acceptStages)
                    ->whereHas('order', function ($q) {
                        $q->where('payment_status', 'paid')
                            ->where('status', 'pending')
                            ->notAwaitingScheduledRelease();
                    })
                    ->with(['order.user', 'site.publisher'])
                    ->limit($limit)
                    ->get()
                    ->map(fn (OrderItem $item) => $this->row($item, 'accept'));
            }

            return $unaccepted->concat($unpublished)
                ->sortByDesc('hours_overdue')
                ->take($limit)
                ->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    public function count(): int
    {
        try {
            if (! Schema::hasColumn('order_items', 'publish_nudge_stage')) {
                return 0;
            }

            $stalledFrom = (int) config('reminders.publisher_publish.stalled_from_stage', 4);
            $acceptStages = count((array) config('reminders.publisher_accept.stages_hours', [12, 36, 72]));

            $unpublished = OrderItem::query()
                ->whereAcceptedAtIsRecorded()
                ->where(fn ($q) => $q->whereNull('live_url')->orWhere('live_url', ''))
                ->where('publish_nudge_stage', '>=', $stalledFrom)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid')
                        ->whereIn('status', ['processing', 'pending'])
                        ->notAwaitingScheduledRelease();
                })
                ->count();

            $unaccepted = 0;
            if (Schema::hasColumn('order_items', 'accept_nudge_stage')) {
                $unaccepted = OrderItem::query()
                    ->whereAcceptedAtIsMissing()
                    ->where('accept_nudge_stage', '>=', $acceptStages)
                    ->whereHas('order', function ($q) {
                        $q->where('payment_status', 'paid')
                            ->where('status', 'pending')
                            ->notAwaitingScheduledRelease();
                    })
                    ->count();
            }

            return $unpublished + $unaccepted;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(OrderItem $item, string $track): array
    {
        $order = $item->order;
        $site = $item->site;

        if ($track === 'accept') {
            $anchor = $order?->paid_at ?? $order?->created_at;
            $hoursOverdue = $anchor ? (int) $anchor->diffInHours(now()) : 0;
            $dueAt = null;
        } else {
            $dueAt = $this->deadlines->for($item, $order, $site);
            $hoursOverdue = $dueAt ? max(0, (int) $dueAt->diffInHours(now(), false)) : 0;
        }

        return [
            'order_item_id' => (int) $item->id,
            'order_id' => (int) ($order->id ?? 0),
            'order_number' => (string) ($order->order_number ?? ''),
            'order_url' => $order?->id ? route('admin.orders.show', $order->id) : null,
            'track' => $track,
            'stage' => (int) ($track === 'accept' ? $item->accept_nudge_stage : $item->publish_nudge_stage),
            'site_name' => (string) ($site?->site_name ?: $item->site_name ?: 'Unknown site'),
            'publisher' => $site?->publisher?->name ?: 'Unknown',
            'publisher_email' => $site?->publisher?->email,
            'advertiser' => $order?->user?->name ?: 'Unknown',
            'amount' => (float) ($item->price ?? 0),
            'due_at' => $dueAt?->format('d M Y H:i'),
            'hours_overdue' => $hoursOverdue,
            'days_overdue' => $hoursOverdue >= 24 ? (int) round($hoursOverdue / 24) : 0,
            'late_label' => $hoursOverdue >= 24
                ? ((int) round($hoursOverdue / 24)).' day(s)'
                : max(0, $hoursOverdue).'h',
            'last_reminded_at' => optional(
                $track === 'accept' ? $item->accept_nudge_sent_at : $item->publish_nudge_sent_at
            )?->format('d M Y H:i'),
        ];
    }
}
