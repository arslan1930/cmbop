<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->unsignedTinyInteger('copy_index')->default(0);
            $table->string('cart_key', 64)->nullable()->index();

            $table->string('original_filename');
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->string('extension', 20);
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->longText('extracted_text')->nullable();
            $table->longText('preview_html')->nullable();
            $table->unsignedInteger('word_count')->default(0);

            $table->string('moderation_status', 40)->default('pending')->index();
            $table->foreignId('moderation_log_id')->nullable()->constrained('content_moderation_logs')->nullOnDelete();
            $table->string('scan_token', 64)->nullable()->index();

            $table->string('anchor_text', 160)->nullable();
            $table->string('target_url', 1000)->nullable();
            $table->string('feature_image_url', 1000)->nullable();

            $table->string('publication_mode', 20)->default('immediate'); // immediate|scheduled
            $table->timestamp('scheduled_publish_at')->nullable()->index();
            $table->string('timezone', 64)->default('UTC');

            $table->unsignedTinyInteger('wizard_step')->default(1);
            $table->json('draft_payload')->nullable();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'site_id', 'copy_index']);
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'publication_mode')) {
                $table->string('publication_mode', 20)->default('immediate')->after('status');
            }
            if (!Schema::hasColumn('orders', 'scheduled_publish_at')) {
                $table->timestamp('scheduled_publish_at')->nullable()->after('publication_mode');
            }
            if (!Schema::hasColumn('orders', 'schedule_timezone')) {
                $table->string('schedule_timezone', 64)->nullable()->after('scheduled_publish_at');
            }
            if (!Schema::hasColumn('orders', 'schedule_released_at')) {
                $table->timestamp('schedule_released_at')->nullable()->after('schedule_timezone');
            }
            if (!Schema::hasColumn('orders', 'schedule_reminder_sent_at')) {
                $table->timestamp('schedule_reminder_sent_at')->nullable()->after('schedule_released_at');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'content_submission_id')) {
                $table->foreignId('content_submission_id')->nullable()->after('content_link')
                    ->constrained('content_submissions')->nullOnDelete();
            }
            if (!Schema::hasColumn('order_items', 'content_disk')) {
                $table->string('content_disk', 40)->nullable()->after('content_submission_id');
            }
            if (!Schema::hasColumn('order_items', 'content_path')) {
                $table->string('content_path')->nullable()->after('content_disk');
            }
            if (!Schema::hasColumn('order_items', 'content_original_name')) {
                $table->string('content_original_name')->nullable()->after('content_path');
            }
            if (!Schema::hasColumn('order_items', 'content_mime')) {
                $table->string('content_mime', 120)->nullable()->after('content_original_name');
            }
            if (!Schema::hasColumn('order_items', 'anchor_text')) {
                $table->string('anchor_text', 160)->nullable()->after('content_mime');
            }
            if (!Schema::hasColumn('order_items', 'target_url')) {
                $table->string('target_url', 1000)->nullable()->after('anchor_text');
            }
            if (!Schema::hasColumn('order_items', 'feature_image_url')) {
                $table->string('feature_image_url', 1000)->nullable()->after('target_url');
            }
            if (!Schema::hasColumn('order_items', 'moderation_status')) {
                $table->string('moderation_status', 40)->nullable()->after('feature_image_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'content_submission_id',
                'content_disk',
                'content_path',
                'content_original_name',
                'content_mime',
                'anchor_text',
                'target_url',
                'feature_image_url',
                'moderation_status',
            ] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    if ($col === 'content_submission_id') {
                        $table->dropConstrainedForeignId('content_submission_id');
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'publication_mode',
                'scheduled_publish_at',
                'schedule_timezone',
                'schedule_released_at',
                'schedule_reminder_sent_at',
            ] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('content_submissions');
    }
};
