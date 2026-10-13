<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'archive_reason')) {
                $table->string('archive_reason', 32)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'archive_reason')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('archive_reason');
        });
    }
};
