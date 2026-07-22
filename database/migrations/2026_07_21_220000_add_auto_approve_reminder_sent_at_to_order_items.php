<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
                $table->timestamp('auto_approve_reminder_sent_at')->nullable()->after('auto_approve_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
                $table->dropColumn('auto_approve_reminder_sent_at');
            }
        });
    }
};
