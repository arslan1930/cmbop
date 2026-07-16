<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AdvertiserAnalyticsService
{
    public function build(User $user, array $filters = []): array
    {
        $paidOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('payment_status', 'paid')
            ->with(['items.site'])
            ->orderBy('created_at')
            ->get();

        $hasSpend = $paidOrders->isNotEmpty();
        $monthly = $this->monthlySeries($paidOrders);
        $comparisons = $this->comparisons($paidOrders);
        $topSites = $this->topSites($paidOrders, $filters['sites_sort'] ?? 'spend');
        $topCategories = $this->topCategories($paidOrders);
        $forecast = $this->forecast($monthly);
        $calendarYear = (int) ($filters['calendar_year'] ?? now()->year);
        $calendar = $this->calendarHeatmap($paidOrders, $calendarYear);
        $records = $this->personalRecords($paidOrders, $topSites, $topCategories);
        $insights = $this->smartInsights($paidOrders, $monthly, $comparisons, $topCategories, $topSites, $records);
        $timeline = $this->timeline($user->id, $filters);

        return [
            'has_spend' => $hasSpend,
            'summary' => [
                'total_spend' => round((float) $paidOrders->sum('total_amount'), 2),
                'total_orders' => $paidOrders->count(),
                'avg_order_value' => $paidOrders->count()
                    ? round((float) $paidOrders->avg('total_amount'), 2)
                    : 0,
                'unique_publishers' => $paidOrders->flatMap->items->pluck('site_id')->filter()->unique()->count(),
            ],
            'monthly' => $monthly,
            'forecast' => $forecast,
            'comparisons' => $comparisons,
            'top_sites' => $topSites,
            'top_categories' => $topCategories,
            'calendar' => $calendar,
            'calendar_year' => $calendarYear,
            'calendar_years' => $this->availableYears($paidOrders),
            'records' => $records,
            'insights' => $insights,
            'timeline' => $timeline,
            'filters' => $filters,
        ];
    }

    protected function monthlySeries(Collection $paidOrders): Collection
    {
        return $paidOrders
            ->groupBy(fn (Order $o) => $o->created_at->format('Y-m'))
            ->map(function (Collection $group, string $month) {
                return [
                    'month' => $month,
                    'label' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                    'spend' => round((float) $group->sum('total_amount'), 2),
                    'orders' => $group->count(),
                    'aov' => round((float) $group->avg('total_amount'), 2),
                ];
            })
            ->sortKeys()
            ->values();
    }

    protected function comparisons(Collection $paidOrders): array
    {
        $now = now();
        $thisMonth = $paidOrders->filter(fn (Order $o) => $o->created_at->isSameMonth($now));
        $lastMonth = $paidOrders->filter(fn (Order $o) => $o->created_at->isSameMonth($now->copy()->subMonth()));
        $thisYear = $paidOrders->filter(fn (Order $o) => $o->created_at->year === $now->year);
        $lastYear = $paidOrders->filter(fn (Order $o) => $o->created_at->year === $now->year - 1);

        return [
            'month' => $this->periodCompare($thisMonth, $lastMonth, 'This month', 'Last month'),
            'year' => $this->periodCompare($thisYear, $lastYear, 'This year', 'Last year'),
        ];
    }

    protected function periodCompare(Collection $current, Collection $previous, string $currentLabel, string $previousLabel): array
    {
        $curSpend = (float) $current->sum('total_amount');
        $prevSpend = (float) $previous->sum('total_amount');
        $curOrders = $current->count();
        $prevOrders = $previous->count();
        $curAov = $curOrders ? $curSpend / $curOrders : 0;
        $prevAov = $prevOrders ? $prevSpend / $prevOrders : 0;

        return [
            'current_label' => $currentLabel,
            'previous_label' => $previousLabel,
            'spend' => $this->delta($curSpend, $prevSpend),
            'orders' => $this->delta($curOrders, $prevOrders, false),
            'aov' => $this->delta($curAov, $prevAov),
        ];
    }

    protected function delta(float|int $current, float|int $previous, bool $money = true): array
    {
        $pct = null;
        if ($previous == 0 && $current == 0) {
            $pct = 0;
        } elseif ($previous == 0) {
            $pct = 100;
        } else {
            $pct = round((($current - $previous) / $previous) * 100, 1);
        }

        return [
            'current' => $money ? round((float) $current, 2) : (int) $current,
            'previous' => $money ? round((float) $previous, 2) : (int) $previous,
            'pct' => $pct,
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
        ];
    }

    protected function topSites(Collection $paidOrders, string $sort = 'spend'): Collection
    {
        $rows = $paidOrders
            ->flatMap(fn (Order $o) => $o->items->map(fn (OrderItem $item) => [
                'site_id' => $item->site_id,
                'site_name' => $item->site_name ?: ($item->site->site_name ?? 'Unknown site'),
                'site_url' => $item->site_url ?: ($item->site->site_url ?? ''),
                'price' => (float) $item->price,
                'ordered_at' => $o->created_at,
            ]))
            ->groupBy(fn ($row) => $row['site_id'] ?: $row['site_url'] ?: $row['site_name'])
            ->map(function (Collection $group) {
                $orders = $group->count();
                $spend = (float) $group->sum('price');
                return [
                    'site_name' => $group->first()['site_name'],
                    'site_url' => $group->first()['site_url'],
                    'orders' => $orders,
                    'total_spend' => round($spend, 2),
                    'aov' => $orders ? round($spend / $orders, 2) : 0,
                    'last_purchase' => $group->max('ordered_at'),
                ];
            })
            ->values();

        return match ($sort) {
            'orders' => $rows->sortByDesc('orders')->values(),
            'recent' => $rows->sortByDesc(fn ($r) => optional($r['last_purchase'])->timestamp ?? 0)->values(),
            default => $rows->sortByDesc('total_spend')->values(),
        };
    }

    protected function topCategories(Collection $paidOrders): Collection
    {
        $bucket = [];
        foreach ($paidOrders as $order) {
            foreach ($order->items as $item) {
                $site = $item->site;
                $categories = [];
                if ($site) {
                    $categories = $site->categories_array ?: (!empty($site->category) ? [$site->category] : []);
                }
                if (empty($categories)) {
                    $categories = ['Uncategorized'];
                }
                $share = (float) $item->price / max(count($categories), 1);
                foreach ($categories as $category) {
                    $key = (string) $category;
                    if (!isset($bucket[$key])) {
                        $bucket[$key] = ['category' => $key, 'spend' => 0.0, 'orders' => 0];
                    }
                    $bucket[$key]['spend'] += $share;
                    $bucket[$key]['orders'] += 1;
                }
            }
        }

        $totalSpend = array_sum(array_column($bucket, 'spend')) ?: 1;

        return collect($bucket)
            ->map(function ($row) use ($totalSpend) {
                return [
                    'category' => $row['category'],
                    'total_spend' => round($row['spend'], 2),
                    'orders' => $row['orders'],
                    'pct' => round(($row['spend'] / $totalSpend) * 100, 1),
                ];
            })
            ->sortByDesc('total_spend')
            ->values();
    }

    protected function forecast(Collection $monthly): array
    {
        if ($monthly->isEmpty()) {
            return [
                'next_month' => 0,
                'next_quarter' => 0,
                'next_year' => 0,
                'series' => [],
                'projection' => [],
            ];
        }

        $avg = (float) $monthly->avg('spend');
        $recent = $monthly->take(-3);
        $older = $monthly->count() > 3 ? $monthly->slice(0, -3) : collect();
        $recentAvg = (float) ($recent->avg('spend') ?: $avg);
        $olderAvg = (float) ($older->avg('spend') ?: $recentAvg);
        $trend = $olderAvg > 0 ? ($recentAvg / $olderAvg) : 1;
        $trend = max(0.7, min(1.4, $trend));

        $nextMonth = round($recentAvg * $trend, 2);
        $nextQuarter = round($nextMonth * 3, 2);
        $nextYear = round($nextMonth * 12, 2);

        $projection = [];
        $cursor = Carbon::createFromFormat('Y-m', $monthly->last()['month'])->startOfMonth();
        for ($i = 1; $i <= 6; $i++) {
            $cursor = $cursor->copy()->addMonth();
            $projection[] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'spend' => round($nextMonth * (1 + (($trend - 1) * 0.15 * $i)), 2),
            ];
        }

        return [
            'next_month' => $nextMonth,
            'next_quarter' => $nextQuarter,
            'next_year' => $nextYear,
            'series' => $monthly->take(-12)->values(),
            'projection' => $projection,
        ];
    }

    protected function calendarHeatmap(Collection $paidOrders, int $year): array
    {
        $days = $paidOrders
            ->filter(fn (Order $o) => (int) $o->created_at->year === $year)
            ->groupBy(fn (Order $o) => $o->created_at->toDateString())
            ->map(fn (Collection $g) => round((float) $g->sum('total_amount'), 2));

        $max = (float) ($days->max() ?: 0);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();
        $cells = [];

        // Pad leading empty cells so columns align to Mon–Sun weeks (GitHub-style).
        $pad = $start->dayOfWeekIso - 1; // Monday = 1
        for ($i = 0; $i < $pad; $i++) {
            $cells[] = [
                'date' => null,
                'spend' => 0,
                'intensity' => -1,
                'weekday' => $i + 1,
                'week' => 0,
            ];
        }

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $spend = (float) ($days[$key] ?? 0);
            $intensity = 0;
            if ($spend > 0 && $max > 0) {
                $intensity = min(4, (int) ceil(($spend / $max) * 4));
            }
            $cells[] = [
                'date' => $key,
                'spend' => $spend,
                'intensity' => $intensity,
                'weekday' => $d->dayOfWeekIso,
                'week' => (int) $d->isoWeek,
            ];
        }

        $activeDays = $days->filter(fn ($v) => $v > 0)->count();
        $totalDays = 365 + (Carbon::create($year)->isLeapYear() ? 1 : 0);

        return [
            'year' => $year,
            'max' => $max,
            'days' => $cells,
            'active_days' => $activeDays,
            'zero_days' => $totalDays - $activeDays,
        ];
    }

    protected function availableYears(Collection $paidOrders): array
    {
        $years = $paidOrders->map(fn (Order $o) => (int) $o->created_at->year)->unique()->sort()->values()->all();
        if (empty($years)) {
            $years = [now()->year];
        }
        return $years;
    }

    protected function personalRecords(Collection $paidOrders, Collection $topSites, Collection $topCategories): array
    {
        if ($paidOrders->isEmpty()) {
            return [];
        }

        $highestOrder = $paidOrders->sortByDesc('total_amount')->first();
        $monthly = $this->monthlySeries($paidOrders);
        $largestMonth = $monthly->sortByDesc('spend')->first();
        $mostExpensiveSite = $topSites->sortByDesc('aov')->first() ?: $topSites->first();
        $mostFrequent = $topSites->sortByDesc('orders')->first();
        $favoriteCategory = $topCategories->first();

        $dates = $paidOrders->pluck('created_at')->sort()->values();
        $streak = 1;
        $bestStreak = 1;
        for ($i = 1; $i < $dates->count(); $i++) {
            $prev = $dates[$i - 1]->copy()->startOfDay();
            $curr = $dates[$i]->copy()->startOfDay();
            if ($prev->diffInDays($curr) === 1) {
                $streak++;
                $bestStreak = max($bestStreak, $streak);
            } elseif ($prev->equalTo($curr)) {
                continue;
            } else {
                $streak = 1;
            }
        }

        return [
            'highest_single_order' => [
                'amount' => round((float) $highestOrder->total_amount, 2),
                'order_number' => $highestOrder->order_number,
                'date' => $highestOrder->created_at,
            ],
            'largest_monthly_spend' => [
                'amount' => $largestMonth['spend'] ?? 0,
                'month' => $largestMonth['label'] ?? '—',
            ],
            'most_expensive_website' => [
                'name' => $mostExpensiveSite['site_name'] ?? '—',
                'aov' => $mostExpensiveSite['aov'] ?? 0,
            ],
            'longest_streak_days' => $bestStreak,
            'most_frequent_website' => [
                'name' => $mostFrequent['site_name'] ?? '—',
                'orders' => $mostFrequent['orders'] ?? 0,
            ],
            'favorite_category' => $favoriteCategory['category'] ?? '—',
            'first_order_date' => $dates->first(),
            'latest_order_date' => $dates->last(),
        ];
    }

    protected function smartInsights(
        Collection $paidOrders,
        Collection $monthly,
        array $comparisons,
        Collection $topCategories,
        Collection $topSites,
        array $records
    ): array {
        if ($paidOrders->isEmpty()) {
            return [];
        }

        $insights = [];

        $m = $comparisons['month']['spend'];
        if ($m['direction'] === 'up') {
            $insights[] = "You spent {$m['pct']}% more this month compared to last month.";
        } elseif ($m['direction'] === 'down') {
            $insights[] = "You spent " . abs($m['pct']) . "% less this month compared to last month.";
        } else {
            $insights[] = 'Your spending this month is about the same as last month.';
        }

        $topCat = $topCategories->first();
        if ($topCat) {
            $insights[] = "{$topCat['category']} websites account for {$topCat['pct']}% of your total investment.";
        }

        $unique = $paidOrders->flatMap->items->pluck('site_id')->filter()->unique()->count();
        $insights[] = "You have purchased from {$unique} unique publisher" . ($unique === 1 ? '' : 's') . '.';

        if (!empty($records['largest_monthly_spend']['month'])) {
            $insights[] = "Your highest spending month was {$records['largest_monthly_spend']['month']}.";
        }

        $aov = $comparisons['month']['aov'];
        if ($aov['direction'] === 'up') {
            $insights[] = 'Your average order value is increasing.';
        } elseif ($aov['direction'] === 'down') {
            $insights[] = 'Your average order value dipped this month — consider reviewing placement mix.';
        }

        if ($topCategories->count() >= 2) {
            $fastest = $topCategories->take(2)->last();
            $insights[] = "{$topCategories->first()['category']} remains your top category; {$fastest['category']} is another active investment area.";
        }

        if ($topSites->isNotEmpty()) {
            $site = $topSites->first();
            $insights[] = "{$site['site_name']} is your most purchased website ({$site['orders']} orders).";
        }

        return array_slice($insights, 0, 6);
    }

    protected function timeline(int $userId, array $filters): Collection
    {
        $query = OrderActivity::query()
            ->with(['order.items:id,order_id,site_name,site_url'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($filters['timeline_from'])) {
            $query->whereDate('created_at', '>=', $filters['timeline_from']);
        }
        if (!empty($filters['timeline_to'])) {
            $query->whereDate('created_at', '<=', $filters['timeline_to']);
        }
        if (!empty($filters['timeline_event'])) {
            $query->where('event', $filters['timeline_event']);
        }
        if (!empty($filters['timeline_order'])) {
            $orderFilter = $filters['timeline_order'];
            $query->whereHas('order', function ($q) use ($orderFilter) {
                $q->where('order_number', 'like', "%{$orderFilter}%")
                    ->orWhere('id', $orderFilter);
            });
        }

        return $query->limit(80)->get()->map(function (OrderActivity $a) {
            $item = $a->order?->items?->first();
            return [
                'id' => $a->id,
                'timestamp' => $a->created_at,
                'event' => $a->event,
                'title' => $a->title,
                'description' => $a->description,
                'order_id' => $a->order_id,
                'order_number' => $a->order?->order_number,
                'website' => $item?->site_name ?? '—',
                'status' => $a->order?->status,
                'icon' => $a->icon ?: 'fa-circle',
                'badge_color' => $a->badge_color ?: '#0b6266',
            ];
        });
    }

    public function exportRows(User $user, string $type, array $filters = []): array
    {
        $data = $this->build($user, $filters);

        return match ($type) {
            'orders' => $this->orderHistoryRows($user, $filters),
            'websites' => collect($data['top_sites'])->map(fn ($r) => [
                'Website' => $r['site_name'],
                'URL' => $r['site_url'],
                'Orders' => $r['orders'],
                'Total Spend' => $r['total_spend'],
                'AOV' => $r['aov'],
                'Last Purchase' => optional($r['last_purchase'])->toDateString(),
            ])->all(),
            'categories' => collect($data['top_categories'])->map(fn ($r) => [
                'Category' => $r['category'],
                'Total Spend' => $r['total_spend'],
                'Orders' => $r['orders'],
                'Share %' => $r['pct'],
            ])->all(),
            'monthly' => collect($data['monthly'])->map(fn ($r) => [
                'Month' => $r['label'],
                'Spend' => $r['spend'],
                'Orders' => $r['orders'],
                'AOV' => $r['aov'],
            ])->all(),
            default => [
                [
                    'Total Spend' => $data['summary']['total_spend'],
                    'Total Orders' => $data['summary']['total_orders'],
                    'Average Order Value' => $data['summary']['avg_order_value'],
                    'Unique Publishers' => $data['summary']['unique_publishers'],
                    'Forecast Next Month' => $data['forecast']['next_month'],
                    'Forecast Next Quarter' => $data['forecast']['next_quarter'],
                    'Forecast Next Year' => $data['forecast']['next_year'],
                    'Calendar Year' => $data['calendar_year'],
                ],
            ],
        };
    }

    protected function orderHistoryRows(User $user, array $filters = []): array
    {
        $query = Order::query()
            ->where('user_id', $user->id)
            ->with('items')
            ->orderByDesc('created_at');

        if (!empty($filters['timeline_from'])) {
            $query->whereDate('created_at', '>=', $filters['timeline_from']);
        }
        if (!empty($filters['timeline_to'])) {
            $query->whereDate('created_at', '<=', $filters['timeline_to']);
        }
        if (!empty($filters['timeline_order'])) {
            $orderFilter = $filters['timeline_order'];
            $query->where(function ($q) use ($orderFilter) {
                $q->where('order_number', 'like', "%{$orderFilter}%")
                    ->orWhere('id', $orderFilter);
            });
        }

        return $query->get()
            ->map(function (Order $o) {
                $item = $o->items->first();
                return [
                    'Order ID' => $o->order_number,
                    'Date' => optional($o->created_at)->toDateTimeString(),
                    'Website' => $item?->site_name ?? '—',
                    'Amount' => $o->total_amount,
                    'Payment Status' => $o->payment_status,
                    'Order Status' => $o->status,
                    'Payment Method' => $o->payment_method,
                ];
            })
            ->all();
    }
}
