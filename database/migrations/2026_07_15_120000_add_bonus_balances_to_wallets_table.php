<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Welcome / promo credit that can be spent on-site but not withdrawn or transferred out.
     * bonus_balance   = promo still sitting in available balance
     * bonus_reserved  = promo currently locked in reserved_balance (open wallet orders)
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->decimal('bonus_balance', 12, 2)->default(0)->after('reserved_balance');
            }
            if (!Schema::hasColumn('wallets', 'bonus_reserved')) {
                $table->decimal('bonus_reserved', 12, 2)->default(0)->after('bonus_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'bonus_reserved')) {
                $table->dropColumn('bonus_reserved');
            }
            if (Schema::hasColumn('wallets', 'bonus_balance')) {
                $table->dropColumn('bonus_balance');
            }
        });
    }
};
