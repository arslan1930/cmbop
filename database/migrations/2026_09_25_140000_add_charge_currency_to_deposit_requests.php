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
            if (! Schema::hasColumn('deposit_requests', 'charge_currency')) {
                $table->string('charge_currency', 3)->nullable();
            }
            if (! Schema::hasColumn('deposit_requests', 'charge_amount')) {
                $table->decimal('charge_amount', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('deposit_requests', 'charge_amount')) {
                $table->dropColumn('charge_amount');
            }
            if (Schema::hasColumn('deposit_requests', 'charge_currency')) {
                $table->dropColumn('charge_currency');
            }
        });
    }
};
