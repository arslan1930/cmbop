<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'reference_code')) {
            return;
        }

        // Production checkout uses short codes, but Stripe/tests/admin tooling can exceed varchar(10).
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY reference_code VARCHAR(64) NULL');
        } else {
            // SQLite / others: best-effort via doctrine-less change
            Schema::table('orders', function ($table) {
                $table->string('reference_code', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'reference_code')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY reference_code VARCHAR(10) NULL');
        }
    }
};
