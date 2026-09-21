<?php

namespace App\Services\Orders;

use App\Models\OrderItem;
use App\Services\LiveUrlHealthChecker;
use Illuminate\Support\Facades\Schema;

/**
 * Recheck live URLs on completed placements after the original submit check.
 */
class CompletedLiveUrlRecheckService
{
    public function __construct(private LiveUrlHealthChecker $checker) {}

    /**
     * @return array{checked: int, down: int, skipped: int}
     */
    public function recheck(int $limit = 40, int $staleDays = 7): array
    {
        $checked = 0;
        $down = 0;
        $skipped = 0;

        if (! Schema::hasTable('order_items')
            || ! Schema::hasColumn('order_items', 'live_url')
            || ! Schema::hasColumn('order_items', 'live_url_check_ok')) {
            return compact('checked', 'down', 'skipped');
        }

        $limit = max(1, min(200, $limit));
        $staleDays = max(1, min(90, $staleDays));
        $staleBefore = now()->subDays($staleDays);

        $query = OrderItem::query()
            ->whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->whereHas('order', function ($orders) {
                $orders->where('status', 'completed')
                    ->where('payment_status', 'paid');
            })
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('live_url_checked_at')
                    ->orWhere('live_url_checked_at', '<=', $staleBefore);
            })
            ->orderByRaw('live_url_checked_at IS NULL DESC')
            ->orderBy('live_url_checked_at')
            ->limit($limit);

        foreach ($query->get() as $item) {
            $url = trim((string) $item->live_url);
            if ($url === '') {
                $skipped++;

                continue;
            }

            $result = $this->checker->check($url);
            $item->applyLiveUrlHealthCheck($result);
            $item->save();
            $checked++;
            if (! ($result['ok'] ?? false)) {
                $down++;
            }
        }

        return compact('checked', 'down', 'skipped');
    }
}
