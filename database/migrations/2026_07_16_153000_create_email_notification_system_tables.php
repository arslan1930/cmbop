<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('subject_override')->nullable();
            $table->timestamps();
        });

        Schema::create('email_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('preference_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'preference_key']);
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('email_logs', 'notification_type')) {
                $table->string('notification_type')->nullable()->after('template_key')->index();
            }
            if (!Schema::hasColumn('email_logs', 'dedupe_key')) {
                $table->string('dedupe_key')->nullable()->after('notification_type')->index();
            }
            if (!Schema::hasColumn('email_logs', 'audience')) {
                $table->string('audience', 32)->nullable()->after('dedupe_key');
            }
        });

        // Seed default admin toggles from config
        $types = array_keys(config('email_notifications.types', []));
        foreach ($types as $type) {
            $enabled = (bool) (config("email_notifications.types.{$type}.default_enabled") ?? true);
            \Illuminate\Support\Facades\DB::table('email_notification_settings')->updateOrInsert(
                ['type' => $type],
                ['enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            foreach (['notification_type', 'dedupe_key', 'audience'] as $col) {
                if (Schema::hasColumn('email_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('email_notification_preferences');
        Schema::dropIfExists('email_notification_settings');
    }
};
