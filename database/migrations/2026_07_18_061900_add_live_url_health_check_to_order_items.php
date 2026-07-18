<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'live_url_http_status')) {
                $table->unsignedSmallInteger('live_url_http_status')->nullable()->after('live_url_submitted_at');
            }
            if (! Schema::hasColumn('order_items', 'live_url_check_ok')) {
                $table->boolean('live_url_check_ok')->nullable()->after('live_url_http_status');
            }
            if (! Schema::hasColumn('order_items', 'live_url_checked_at')) {
                $table->timestamp('live_url_checked_at')->nullable()->after('live_url_check_ok');
            }
            // Advertiser change-request reason shown to publishers
            if (! Schema::hasColumn('order_items', 'completion_notes')) {
                $table->text('completion_notes')->nullable()->after('auto_approve_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            foreach (['completion_notes', 'live_url_checked_at', 'live_url_check_ok', 'live_url_http_status'] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
