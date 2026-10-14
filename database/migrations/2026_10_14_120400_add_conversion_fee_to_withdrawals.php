<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawals', 'conversion_fee')) {
                $table->decimal('conversion_fee', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('withdrawals', 'conversion_fee_percent')) {
                $table->decimal('conversion_fee_percent', 5, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('withdrawals', 'conversion_fee')) {
                $table->dropColumn('conversion_fee');
            }
            if (Schema::hasColumn('withdrawals', 'conversion_fee_percent')) {
                $table->dropColumn('conversion_fee_percent');
            }
        });
    }
};
