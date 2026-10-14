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
            if (! Schema::hasColumn('deposit_requests', 'bank_account')) {
                $table->string('bank_account', 32)->nullable();
            }
            if (! Schema::hasColumn('deposit_requests', 'payment_reference')) {
                $table->string('payment_reference', 48)->nullable();
            }
            if (! Schema::hasColumn('deposit_requests', 'received_bank')) {
                $table->string('received_bank', 32)->nullable();
            }
        });

        if (Schema::hasColumn('deposit_requests', 'payment_reference')) {
            try {
                Schema::table('deposit_requests', function (Blueprint $table) {
                    $table->unique('payment_reference', 'deposit_requests_payment_reference_unique');
                });
            } catch (Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('deposit_requests', 'payment_reference')) {
                try {
                    $table->dropUnique('deposit_requests_payment_reference_unique');
                } catch (Throwable) {
                }
            }
            foreach (['received_bank', 'payment_reference', 'bank_account'] as $column) {
                if (Schema::hasColumn('deposit_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
