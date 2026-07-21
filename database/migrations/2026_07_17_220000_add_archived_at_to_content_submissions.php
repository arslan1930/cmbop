<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        Schema::table('content_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('content_submissions', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('expires_at')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        Schema::table('content_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('content_submissions', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }
};
