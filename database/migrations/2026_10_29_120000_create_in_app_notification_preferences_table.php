<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('in_app_notification_preferences')) {
            return;
        }

        Schema::create('in_app_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category'], 'in_app_notif_prefs_user_category_unique');
            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notification_preferences');
    }
};
