<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
                $table->timestamp('user_marked_paid_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('deposit_requests', 'user_payment_note')) {
                $table->string('user_payment_note', 255)->nullable()->after('user_marked_paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('deposit_requests', 'user_payment_note')) {
                $table->dropColumn('user_payment_note');
            }
            if (Schema::hasColumn('deposit_requests', 'user_marked_paid_at')) {
                $table->dropColumn('user_marked_paid_at');
            }
        });
    }
};
