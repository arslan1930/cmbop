<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostinger/production sometimes created onboarding_status as ENUM or VARCHAR(16).
 * ready_for_review is 17 chars and is not in the ENUM → MySQL 1265 Data truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'onboarding_status')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `sites` MODIFY `onboarding_status` VARCHAR(32) NULL');
    }

    public function down(): void
    {
        // Intentionally left blank — narrowing can truncate live values.
    }
};
