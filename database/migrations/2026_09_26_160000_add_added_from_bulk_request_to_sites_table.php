<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'added_from_bulk_request')) {
                $table->boolean('added_from_bulk_request')->default(false);
            }
        });

        if (Schema::hasColumn('sites', 'added_from_bulk_request')
            && Schema::hasColumn('sites', 'bulk_site_request_id')) {
            DB::table('sites')
                ->whereNotNull('bulk_site_request_id')
                ->update(['added_from_bulk_request' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'added_from_bulk_request')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('added_from_bulk_request');
        });
    }
};
