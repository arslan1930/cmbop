<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'is_sandbox')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('is_sandbox')->default(false)->index();
            });
        }
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'is_sandbox')) {
                    $table->boolean('is_sandbox')->default(false)->index();
                }
                if (! Schema::hasColumn('order_items', 'live_url_href_found')) {
                    $table->boolean('live_url_href_found')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'live_url_rel')) {
                    $table->string('live_url_rel', 64)->nullable();
                }
                if (! Schema::hasColumn('order_items', 'live_url_anchor_match')) {
                    $table->boolean('live_url_anchor_match')->nullable();
                }
            });
        }
        if (Schema::hasTable('partner_webhook_events') && ! Schema::hasColumn('partner_webhook_events', 'livemode')) {
            Schema::table('partner_webhook_events', function (Blueprint $table) {
                $table->boolean('livemode')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'is_sandbox')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('is_sandbox');
            });
        }
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                foreach (['is_sandbox', 'live_url_href_found', 'live_url_rel', 'live_url_anchor_match'] as $column) {
                    if (Schema::hasColumn('order_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
        if (Schema::hasTable('partner_webhook_events') && Schema::hasColumn('partner_webhook_events', 'livemode')) {
            Schema::table('partner_webhook_events', function (Blueprint $table) {
                $table->dropColumn('livemode');
            });
        }
    }
};
