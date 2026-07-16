<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AdvertiserAnalyticsService
{
    /**
     * Build full spending history for chart toggles (order / day / month).
     * Covers every paid order from the advertiser's first purchase onward.
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
                'label' => $order->order_number ?: ('#' . $order->id),
                'date' => optional($order->created_at)->toDateString(),
                'datetime' => optional($order->created_at)->toDateTimeString(),
                'amount' => round((float) $order->total_amount, 2),
                'website' => $item?->site_name ?? '—',
            ];
        })->values()->all();
    }

    protected function byDay(Collection $paidOrders): array
    {
        if ($paidOrders->isEmpty()) {
            return [];
        }

        $grouped = $paidOrders->groupBy(fn (Order $o) => $o->created_at->toDateString());
        $start = $paidOrders->first()->created_at->copy()->startOfDay();
        $end = $paidOrders->last()->created_at->copy()->startOfDay();

        $series = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $bucket = $grouped->get($key, collect());
            $series[] = [
                'label' => $day->format('M j, Y'),
                'date' => $key,
                'amount' => round((float) $bucket->sum('total_amount'), 2),
                'orders' => $bucket->count(),
            ];
        }

        return $series;
    }

    protected function byMonth(Collection $paidOrders): array
    {
        if ($paidOrders->isEmpty()) {
            return [];
        }

        $grouped = $paidOrders->groupBy(fn (Order $o) => $o->created_at->format('Y-m'));
        $start = $paidOrders->first()->created_at->copy()->startOfMonth();
        $end = $paidOrders->last()->created_at->copy()->startOfMonth();

        $series = [];
        for ($month = $start->copy(); $month->lte($end); $month->addMonth()) {
            $key = $month->format('Y-m');
            $bucket = $grouped->get($key, collect());
            $series[] = [
                'label' => $month->format('M Y'),
                'date' => $key,
                'amount' => round((float) $bucket->sum('total_amount'), 2),
                'orders' => $bucket->count(),
            ];
        }

        return $series;
    }
}
