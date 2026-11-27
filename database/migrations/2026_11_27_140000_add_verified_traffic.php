<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_traffic_connections')) {
            Schema::create('site_traffic_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('google_account_email')->nullable();
                $table->string('google_account_id', 64)->nullable();
                $table->text('access_token_encrypted');
                $table->text('refresh_token_encrypted')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->json('scopes')->nullable();
                $table->string('ga4_property_id', 64)->nullable();
                $table->string('ga4_property_name', 191)->nullable();
                $table->string('gsc_site_url')->nullable();
                $table->string('status', 32)->default('pending');
                $table->string('last_error', 500)->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamp('last_notified_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'last_synced_at'], 'site_traffic_connections_status_synced_index');
            });
        }

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'traffic_manual')) {
                $table->unsignedInteger('traffic_manual')->nullable();
            }
            if (! Schema::hasColumn('sites', 'traffic_ga4')) {
                $table->unsignedInteger('traffic_ga4')->nullable();
            }
            if (! Schema::hasColumn('sites', 'traffic_gsc_clicks')) {
                $table->unsignedInteger('traffic_gsc_clicks')->nullable();
            }
            if (! Schema::hasColumn('sites', 'traffic_admin_override')) {
                $table->unsignedInteger('traffic_admin_override')->nullable();
            }
            if (! Schema::hasColumn('sites', 'traffic_source')) {
                $table->string('traffic_source', 16)->default('manual');
            }
            if (! Schema::hasColumn('sites', 'traffic_verified_at')) {
                $table->timestamp('traffic_verified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            foreach ([
                'traffic_manual',
                'traffic_ga4',
                'traffic_gsc_clicks',
                'traffic_admin_override',
                'traffic_source',
                'traffic_verified_at',
            ] as $column) {
                if (Schema::hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('site_traffic_connections');
    }
};
