<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddRefundedToPaymentStatus extends Migration
{
    public function up()
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending'");
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'live_url_submitted_at')) {
                $table->timestamp('live_url_submitted_at')->nullable()->after('live_url');
            }
        });
    }

    public function down()
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending'");
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'live_url_submitted_at')) {
                $table->dropColumn('live_url_submitted_at');
            }
        });
    }
}
