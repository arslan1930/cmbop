<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'site_image')) {
                $table->string('site_image')->nullable()->after('site_url');
            }
            if (! Schema::hasColumn('sites', 'screenshot_path')) {
                $table->string('screenshot_path')->nullable()->after('site_image');
            }
            if (! Schema::hasColumn('sites', 'screenshot_thumb_path')) {
                $table->string('screenshot_thumb_path')->nullable()->after('screenshot_path');
            }
            if (! Schema::hasColumn('sites', 'favicon_path')) {
                $table->string('favicon_path')->nullable()->after('screenshot_thumb_path');
            }
            if (! Schema::hasColumn('sites', 'metrics_provider')) {
                $table->string('metrics_provider', 40)->nullable()->after('traffic');
            }
            if (! Schema::hasColumn('sites', 'metrics_fetched_at')) {
                $table->timestamp('metrics_fetched_at')->nullable()->after('metrics_provider');
            }
            if (! Schema::hasColumn('sites', 'screenshot_fetched_at')) {
                $table->timestamp('screenshot_fetched_at')->nullable()->after('metrics_fetched_at');
            }
            if (! Schema::hasColumn('sites', 'enrichment_status')) {
                $table->string('enrichment_status', 20)->default('pending')->after('screenshot_fetched_at')->index();
            }
            if (! Schema::hasColumn('sites', 'enrichment_error')) {
                $table->text('enrichment_error')->nullable()->after('enrichment_status');
            }
            if (! Schema::hasColumn('sites', 'metrics_manual')) {
                $table->boolean('metrics_manual')->default(false)->after('enrichment_error');
            }
        });

        if (! Schema::hasTable('site_enrichment_runs')) {
            Schema::create('site_enrichment_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->string('type', 30); // metrics|screenshot|full
                $table->string('provider', 40)->nullable();
                $table->string('status', 20); // running|success|partial|failed
                $table->json('payload')->nullable();
                $table->text('error')->nullable();
                $table->string('triggered_by', 40)->nullable(); // system|admin|verify|schedule
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'type', 'created_at']);
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_enrichment_runs');

        Schema::table('sites', function (Blueprint $table) {
            foreach ([
                'metrics_manual',
                'enrichment_error',
                'enrichment_status',
                'screenshot_fetched_at',
                'metrics_fetched_at',
                'metrics_provider',
                'favicon_path',
                'screenshot_thumb_path',
                'screenshot_path',
            ] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
