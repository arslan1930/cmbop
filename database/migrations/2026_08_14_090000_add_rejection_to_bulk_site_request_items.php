<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_site_request_items', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('site_id');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 500)->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_site_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'reject_reason']);
        });
    }
};
