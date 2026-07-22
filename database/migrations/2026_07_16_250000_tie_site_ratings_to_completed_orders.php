<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'completed_orders_count')) {
                // No after() — rating_count may be absent on older schemas.
                $table->unsignedInteger('completed_orders_count')->default(0);
            }
        });

        Schema::table('site_ratings', function (Blueprint $table) {
            if (! Schema::hasColumn('site_ratings', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('user_id')->constrained('orders')->nullOnDelete();
            }
            if (! Schema::hasColumn('site_ratings', 'order_item_id')) {
                $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained('order_items')->nullOnDelete();
            }
        });

        try {
            Schema::table('site_ratings', function (Blueprint $table) {
                $table->dropUnique(['site_id', 'user_id']);
            });
        } catch (Throwable) {
            // Already dropped or named differently.
        }

        try {
            Schema::table('site_ratings', function (Blueprint $table) {
                $table->unique('order_item_id');
            });
        } catch (Throwable) {
            // Already exists.
        }

        try {
            Schema::table('site_ratings', function (Blueprint $table) {
                $table->index(['site_id', 'user_id']);
            });
        } catch (Throwable) {
            // Already exists.
        }

        if (Schema::hasTable('order_items') && Schema::hasTable('orders')) {
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
    }

    public function down(): void
    {
        try {
            Schema::table('site_ratings', function (Blueprint $table) {
                $table->dropUnique(['order_item_id']);
            });
        } catch (Throwable) {
        }

        Schema::table('site_ratings', function (Blueprint $table) {
            if (Schema::hasColumn('site_ratings', 'order_item_id')) {
                $table->dropConstrainedForeignId('order_item_id');
            }
            if (Schema::hasColumn('site_ratings', 'order_id')) {
                $table->dropConstrainedForeignId('order_id');
            }
        });

        try {
            Schema::table('site_ratings', function (Blueprint $table) {
                $table->unique(['site_id', 'user_id']);
            });
        } catch (Throwable) {
        }

        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'completed_orders_count')) {
                $table->dropColumn('completed_orders_count');
            }
        });
    }
};
