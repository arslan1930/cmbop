<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_moderation_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('content_moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('document_url', 1000);
            $table->string('document_id')->nullable()->index();
            $table->string('status', 40); // approved|rejected|error
            $table->boolean('passed')->default(false);
            $table->unsignedTinyInteger('max_confidence')->default(0);
            $table->string('detected_category')->nullable();
            $table->json('category_scores')->nullable();
            $table->json('quality_report')->nullable();
            $table->json('signals')->nullable(); // non-sensitive signals for admin
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('scan_token', 64)->nullable()->index();
            $table->boolean('admin_override')->default(false);
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['passed', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_moderation_logs');
        Schema::dropIfExists('content_moderation_settings');
    }
};
