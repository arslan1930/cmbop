<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_announcements')) {
            Schema::table('site_announcements', function (Blueprint $table) {
                if (! Schema::hasColumn('site_announcements', 'version')) {
                    $table->unsignedInteger('version')->default(1)->after('priority');
                }
                if (! Schema::hasColumn('site_announcements', 'clicks')) {
                    $table->unsignedBigInteger('clicks')->default(0)->after('version');
                }
                if (! Schema::hasColumn('site_announcements', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        if (Schema::hasTable('ad_banners') && ! Schema::hasColumn('ad_banners', 'deleted_at')) {
            Schema::table('ad_banners', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('promotion_events')) {
            Schema::create('promotion_events', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->string('event', 20);
                $table->string('visitor_hash', 64);
                $table->date('occurred_on');
                $table->timestamp('created_at')->nullable();

                $table->unique(
                    ['subject_type', 'subject_id', 'event', 'visitor_hash', 'occurred_on'],
                    'promotion_events_unique_daily'
                );
                $table->index(['subject_type', 'subject_id', 'event', 'occurred_on'], 'promotion_events_subject_day');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_events');

        if (Schema::hasTable('site_announcements')) {
            Schema::table('site_announcements', function (Blueprint $table) {
                if (Schema::hasColumn('site_announcements', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
                if (Schema::hasColumn('site_announcements', 'clicks')) {
                    $table->dropColumn('clicks');
                }
                if (Schema::hasColumn('site_announcements', 'version')) {
                    $table->dropColumn('version');
                }
            });
        }

        if (Schema::hasTable('ad_banners') && Schema::hasColumn('ad_banners', 'deleted_at')) {
            Schema::table('ad_banners', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
