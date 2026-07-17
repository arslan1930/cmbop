<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'payout_business_name')) {
                $table->string('payout_business_name')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'payout_paypal_email')) {
                $table->string('payout_paypal_email')->nullable()->after('payout_business_name');
            }
            if (! Schema::hasColumn('users', 'payout_bank_holder_name')) {
                $table->string('payout_bank_holder_name')->nullable()->after('payout_paypal_email');
            }
            if (! Schema::hasColumn('users', 'payout_bank_name')) {
                $table->string('payout_bank_name')->nullable()->after('payout_bank_holder_name');
            }
            if (! Schema::hasColumn('users', 'payout_bank_account')) {
                $table->string('payout_bank_account')->nullable()->after('payout_bank_name');
            }
            if (! Schema::hasColumn('users', 'payout_bank_swift')) {
                $table->string('payout_bank_swift', 50)->nullable()->after('payout_bank_account');
            }
            if (! Schema::hasColumn('users', 'payout_crypto_trx_wallet')) {
                $table->string('payout_crypto_trx_wallet')->nullable()->after('payout_bank_swift');
            }
            if (! Schema::hasColumn('users', 'payout_crypto_trx_verified_at')) {
                $table->timestamp('payout_crypto_trx_verified_at')->nullable()->after('payout_crypto_trx_wallet');
            }
            if (! Schema::hasColumn('users', 'payout_profile_locked_at')) {
                $table->timestamp('payout_profile_locked_at')->nullable()->after('payout_crypto_trx_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'payout_profile_locked_at',
                'payout_crypto_trx_verified_at',
                'payout_crypto_trx_wallet',
                'payout_bank_swift',
                'payout_bank_account',
                'payout_bank_name',
                'payout_bank_holder_name',
                'payout_paypal_email',
                'payout_business_name',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
