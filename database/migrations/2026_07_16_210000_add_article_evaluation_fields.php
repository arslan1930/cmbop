<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('content_submissions', 'title')) {
                $table->string('title')->nullable()->after('original_filename');
            }
            if (!Schema::hasColumn('content_submissions', 'uniqueness_score')) {
                $table->unsignedTinyInteger('uniqueness_score')->nullable()->after('word_count');
            }
            if (!Schema::hasColumn('content_submissions', 'quality_score')) {
                $table->unsignedTinyInteger('quality_score')->nullable()->after('uniqueness_score');
            }
            if (!Schema::hasColumn('content_submissions', 'evaluation_status')) {
                $table->string('evaluation_status', 40)->default('pending')->after('quality_score')->index();
            }
            if (!Schema::hasColumn('content_submissions', 'evaluation_report')) {
                $table->json('evaluation_report')->nullable()->after('evaluation_status');
            }
            if (!Schema::hasColumn('content_submissions', 'evaluated_at')) {
                $table->timestamp('evaluated_at')->nullable()->after('evaluation_report');
            }
            if (!Schema::hasColumn('content_submissions', 'approval_notified_at')) {
                $table->timestamp('approval_notified_at')->nullable()->after('evaluated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_submissions', function (Blueprint $table) {
            foreach ([
                'title',
                'uniqueness_score',
                'quality_score',
                'evaluation_status',
                'evaluation_report',
                'evaluated_at',
                'approval_notified_at',
            ] as $col) {
                if (Schema::hasColumn('content_submissions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
