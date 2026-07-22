<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure sites.completed_orders_count exists.
 *
 * Some environments never applied 2026_07_16_250000 (or it failed on
 * after('rating_count')), which breaks advertiser order approval when
 * Site::refreshCompletedOrdersCount() runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        if (! Schema::hasColumn('sites', 'completed_orders_count')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->unsignedInteger('completed_orders_count')->default(0);
            });
        }

        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return;
        }

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->select('order_items.site_id', DB::raw('COUNT(*) as total'))
            ->groupBy('order_items.site_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('sites')->where('id', $row->site_id)->update([
                'completed_orders_count' => (int) $row->total,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally keep the column — dropping would re-break approvals.
    }
};
