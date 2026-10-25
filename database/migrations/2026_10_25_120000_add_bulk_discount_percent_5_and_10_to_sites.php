<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'bulk_discount_percent_5')) {
                $table->decimal('bulk_discount_percent_5', 5, 2)->nullable()->after('bulk_discount_percent');
            }
            if (! Schema::hasColumn('sites', 'bulk_discount_percent_10')) {
                $table->decimal('bulk_discount_percent_10', 5, 2)->nullable()->after('bulk_discount_percent_5');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            foreach (['bulk_discount_percent_10', 'bulk_discount_percent_5'] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
