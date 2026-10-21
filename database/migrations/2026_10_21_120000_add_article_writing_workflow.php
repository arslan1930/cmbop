<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'writing_status')) {
                    $table->string('writing_status', 32)->nullable();
                }
                if (! Schema::hasColumn('order_items', 'writing_assignee_id')) {
                    $table->unsignedBigInteger('writing_assignee_id')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'writing_deadline_at')) {
                    $table->timestamp('writing_deadline_at')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'writing_brief_notes')) {
                    $table->text('writing_brief_notes')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'writing_language')) {
                    $table->string('writing_language', 16)->nullable();
                }
                if (! Schema::hasColumn('order_items', 'article_revision_used')) {
                    $table->unsignedTinyInteger('article_revision_used')->default(0);
                }
                if (! Schema::hasColumn('order_items', 'article_submitted_at')) {
                    $table->timestamp('article_submitted_at')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'article_approved_at')) {
                    $table->timestamp('article_approved_at')->nullable();
                }
                if (! Schema::hasColumn('order_items', 'writing_reminder_sent_at')) {
                    $table->timestamp('writing_reminder_sent_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'link_guarantee_publisher_choice')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('link_guarantee_publisher_choice', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Hostinger skip-migration: keep columns.
    }
};
