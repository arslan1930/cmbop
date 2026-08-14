<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_site_requests', function (Blueprint $table) {
            $table->string('cancel_reason', 500)->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_site_requests', function (Blueprint $table) {
            $table->dropColumn('cancel_reason');
        });
    }
};
