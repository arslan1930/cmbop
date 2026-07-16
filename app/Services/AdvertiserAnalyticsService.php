<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

class AdvertiserAnalyticsService
{
    /**
     * Build full spending history for chart toggles (order / day / month).
     * Day and month buckets align to dates that actually have paid orders.
     */
    public function build(User $user): array
    {
        $paidOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('payment_status', 'paid')
            ->with(['items'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'has_spend' => $paidOrders->isNotEmpty(),
            'total_spend' => round((float) $paidOrders->sum('total_amount'), 2),
            'total_orders' => $paidOrders->count(),
            'first_order_at' => optional($paidOrders->first())->created_at,
            'last_order_at' => optional($paidOrders->last())->created_at,
            'by_order' => $this->byOrder($paidOrders),
            'by_day' => $this->byDay($paidOrders),
            'by_month' => $this->byMonth($paidOrders),
        ];
    }

    protected function byOrder(Collection $paidOrders): array
    {
        return $paidOrders->map(function (Order $order) {
            $item = $order->items->first();

            return [
                'label' => optional($order->created_at)->format('M j') . ' · ' . ($order->order_number ?: ('#' . $order->id)),
                'short_label' => $order->order_number ?: ('#' . $order->id),
                'date' => optional($order->created_at)->toDateString(),
                'datetime' => optional($order->created_at)->toDateTimeString(),
                'amount' => round((float) $order->total_amount, 2),
                'orders' => 1,
                'website' => $item?->site_name ?? '—',
            ];
        })->values()->all();
    }

    protected function byDay(Collection $paidOrders): array
    {
        if ($paidOrders->isEmpty()) {
            return [];
        }

        return $paidOrders
            ->groupBy(fn (Order $o) => $o->created_at->toDateString())
            ->sortKeys()
            ->map(function (Collection $bucket, string $key) {
                $day = $bucket->first()->created_at;

                return [
                    'label' => $day->format('M j, Y'),
                    'short_label' => $day->format('M j'),
                    'date' => $key,
                    'amount' => round((float) $bucket->sum('total_amount'), 2),
                    'orders' => $bucket->count(),
                ];
            })
            ->values()
            ->all();
    }

    protected function byMonth(Collection $paidOrders): array
    {
        if ($paidOrders->isEmpty()) {
            return [];
        }

        return $paidOrders
            ->groupBy(fn (Order $o) => $o->created_at->format('Y-m'))
            ->sortKeys()
            ->map(function (Collection $bucket, string $key) {
                $month = $bucket->first()->created_at;

                return [
                    'label' => $month->format('M Y'),
                    'short_label' => $month->format('M Y'),
                    'date' => $key,
                    'amount' => round((float) $bucket->sum('total_amount'), 2),
                    'orders' => $bucket->count(),
                ];
            })
            ->values()
            ->all();
    }
}
