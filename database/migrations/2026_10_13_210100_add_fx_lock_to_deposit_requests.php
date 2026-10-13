<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('deposit_requests', 'fx_rate')) {
                $table->decimal('fx_rate', 18, 8)->nullable();
            }
            if (! Schema::hasColumn('deposit_requests', 'rate_locked_until')) {
                $table->timestamp('rate_locked_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('deposit_requests', 'rate_locked_until')) {
                $table->dropColumn('rate_locked_until');
            }
            if (Schema::hasColumn('deposit_requests', 'fx_rate')) {
                $table->dropColumn('fx_rate');
            }
        });
    }
};
