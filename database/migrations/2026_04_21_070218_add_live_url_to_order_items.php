<?php
// database/migrations/2024_01_01_000002_add_live_url_to_order_items.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLiveUrlToOrderItems extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'live_url')) {
                $table->string('live_url')->nullable()->after('content_link');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('order_items') || !Schema::hasColumn('order_items', 'live_url')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('live_url');
        });
    }
}