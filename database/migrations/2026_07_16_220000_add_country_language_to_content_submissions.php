<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('content_submissions', 'country')) {
                $table->string('country', 10)->nullable()->after('title')->index();
            }
            if (!Schema::hasColumn('content_submissions', 'language')) {
                $table->string('language', 10)->nullable()->after('country')->index();
            }
            if (!Schema::hasIndex('content_submissions', 'content_submissions_language_country_index')) {
                $table->index(['language', 'country']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_submissions', function (Blueprint $table) {
            if (Schema::hasIndex('content_submissions', 'content_submissions_language_country_index')) {
                $table->dropIndex('content_submissions_language_country_index');
            }
            foreach (['language', 'country'] as $col) {
                if (Schema::hasColumn('content_submissions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
