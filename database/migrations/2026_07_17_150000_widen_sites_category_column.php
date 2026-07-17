<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `sites` MODIFY `category` TEXT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sites ALTER COLUMN category TYPE TEXT');
            DB::statement('ALTER TABLE sites ALTER COLUMN category DROP NOT NULL');
            return;
        }

        // SQLite (tests): recreate is unnecessary when column already accepts long strings
        // via affinity; skip when change() is unavailable.
        try {
            Schema::table('sites', function ($table) {
                $table->text('category')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // Ignore — SQLite affinity already stores long category CSVs.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `sites` MODIFY `category` VARCHAR(50) NOT NULL');
        }
    }
};
