<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'site_feature_purchases'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'charge_currency')) {
                    $table->string('charge_currency', 3)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'charge_amount')) {
                    $table->decimal('charge_amount', 12, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'site_feature_purchases'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'charge_amount')) {
                    $table->dropColumn('charge_amount');
                }
                if (Schema::hasColumn($tableName, 'charge_currency')) {
                    $table->dropColumn('charge_currency');
                }
            });
        }
    }
};
