<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'payout_wise_email')) {
                $table->string('payout_wise_email')->nullable()->after('payout_paypal_email');
            }
            if (! Schema::hasColumn('users', 'payout_crypto_type')) {
                $table->string('payout_crypto_type', 32)->nullable()->after('payout_crypto_trx_wallet');
            }
            if (! Schema::hasColumn('users', 'payout_preferred_method')) {
                $table->string('payout_preferred_method', 20)->nullable()->after('payout_profile_locked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['payout_preferred_method', 'payout_crypto_type', 'payout_wise_email'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
