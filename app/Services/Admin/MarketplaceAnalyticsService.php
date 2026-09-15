<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketplaceAnalyticsService
{
    /**
     * @param  array{start: mixed, end: mixed, key: string, label: string}  $period
     * @return array<string, mixed>
     */
    public function snapshot(array $period): array
    {
        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;

        return [
            'period' => $period,
            'kpis' => $this->kpis($start, $end),
            'niches' => $this->topNiches(),
            'countries' => $this->topCountries(),
            'niche_gmv' => $this->nicheGmv($start, $end),
        ];
    }

    /**
     * @return list<array{section: string, metric: string, value: string}>
     */
    public function exportRows(array $period): array
    {
        $data = $this->snapshot($period);
        $rows = [
            ['section' => 'period', 'metric' => 'label', 'value' => (string) ($period['label'] ?? '')],
            ['section' => 'period', 'metric' => 'key', 'value' => (string) ($period['key'] ?? '')],
        ];

        foreach ($data['kpis'] as $key => $value) {
            $rows[] = [
                'section' => 'kpi',
                'metric' => (string) $key,
                'value' => (string) $value,
            ];
        }

        foreach ($data['niches'] as $row) {
            $rows[] = [
                'section' => 'live_listings_by_niche',
                'metric' => (string) $row['label'],
                'value' => (string) $row['count'],
            ];
        }

        foreach ($data['countries'] as $row) {
            $rows[] = [
                'section' => 'live_listings_by_country',
                'metric' => (string) $row['label'],
                'value' => (string) $row['count'],
            ];
        }

        foreach ($data['niche_gmv'] as $row) {
            $rows[] = [
                'section' => 'paid_gmv_by_niche',
                'metric' => (string) $row['label'],
                'value' => (string) $row['gmv'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int|float>
     */
    private function kpis(mixed $start, mixed $end): array
    {
        $paid = Order::query()->where('payment_status', 'paid');
        $this->constrainDate($paid, 'paid_at', $start, $end);

        $completed = Order::query()->where('status', 'completed');
        $this->constrainDate($completed, 'completed_at', $start, $end);

        $users = User::query();
        $this->constrainDate($users, 'created_at', $start, $end);

        $sites = Site::query();
        $this->constrainDate($sites, 'created_at', $start, $end);

        return [
            'paid_gmv' => round((float) (clone $paid)->sum('total_amount'), 2),
            'paid_orders' => (int) (clone $paid)->count(),
            'completed_orders' => (int) (clone $completed)->count(),
            'new_users' => (int) $users->count(),
            'new_advertisers' => $this->newUsersWithRole('advertiser', $start, $end),
            'new_publishers' => $this->newUsersWithRole('publisher', $start, $end),
            'new_sites' => (int) $sites->count(),
            'live_sites' => (int) Site::query()->catalogVisible()->count(),
        ];
    }

    private function newUsersWithRole(string $role, mixed $start, mixed $end): int
    {
        $query = User::query()->whereHas('roles', fn ($q) => $q->where('name', $role));
        $this->constrainDate($query, 'created_at', $start, $end);

        return (int) $query->count();
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function topNiches(int $limit = 15): array
    {
        if (! Schema::hasTable('sites')) {
            return [];
        }

        return Site::query()
            ->catalogVisible()
            ->select('category', DB::raw('COUNT(*) as total'))
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->category,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function topCountries(int $limit = 15): array
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'country')) {
            return [];
        }

        return Site::query()
            ->catalogVisible()
            ->select('country', DB::raw('COUNT(*) as total'))
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'label' => strtoupper((string) $row->country),
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, gmv: float, lines: int}>
     */
    private function nicheGmv(mixed $start, mixed $end, int $limit = 15): array
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return [];
        }

        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('sites', 'sites.id', '=', 'order_items.site_id')
            ->where('orders.payment_status', 'paid');

        if ($start) {
            $query->where('orders.paid_at', '>=', $start);
        }
        if ($end) {
            $query->where('orders.paid_at', '<=', $end);
        }

        $nicheExpr = Schema::hasColumn('sites', 'category')
            ? "COALESCE(NULLIF(sites.category, ''), order_items.site_name, 'Unknown')"
            : "COALESCE(order_items.site_name, 'Unknown')";

        return $query
            ->selectRaw($nicheExpr.' as niche')
            ->selectRaw('SUM(order_items.price) as gmv')
            ->selectRaw('COUNT(*) as lines')
            ->groupByRaw($nicheExpr)
            ->orderByDesc('gmv')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->niche,
                'gmv' => round((float) $row->gmv, 2),
                'lines' => (int) $row->lines,
            ])
            ->values()
            ->all();
    }

    private function constrainDate($query, string $column, mixed $start, mixed $end): void
    {
        if ($start) {
            $query->where($column, '>=', $start);
        }
        if ($end) {
            $query->where($column, '<=', $end);
        }
    }
}
