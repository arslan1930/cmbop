<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawals', 'currency')) {
                $table->string('currency', 3)->default('EUR');
            }
        });

        if (Schema::hasColumn('withdrawals', 'currency') && Schema::hasColumn('withdrawals', 'wallet_id') && Schema::hasTable('wallets')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $rows = DB::table('withdrawals')
                    ->leftJoin('wallets', 'withdrawals.wallet_id', '=', 'wallets.id')
                    ->select('withdrawals.id', 'wallets.currency')
                    ->get();
                foreach ($rows as $row) {
                    $currency = strtoupper(trim((string) ($row->currency ?: 'EUR'))) ?: 'EUR';
                    DB::table('withdrawals')->where('id', $row->id)->update(['currency' => $currency]);
                }
            } else {
                DB::statement('UPDATE withdrawals w LEFT JOIN wallets wa ON w.wallet_id = wa.id SET w.currency = COALESCE(NULLIF(wa.currency, \'\'), \'EUR\')');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('withdrawals', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
