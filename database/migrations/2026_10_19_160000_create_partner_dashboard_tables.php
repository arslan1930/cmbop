<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partners')) {
            Schema::table('partners', function (Blueprint $table) {
                if (! Schema::hasColumn('partners', 'notification_preferences')) {
                    $table->json('notification_preferences')->nullable();
                }
                if (! Schema::hasColumn('partners', 'security_review_at')) {
                    $table->timestamp('security_review_at')->nullable();
                }
                if (! Schema::hasColumn('partners', 'one_click_links')) {
                    $table->boolean('one_click_links')->nullable();
                }
            });
        }

        if (! Schema::hasTable('partner_email_action_tokens')) {
            Schema::create('partner_email_action_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->string('token_hash', 64)->unique();
                $table->json('actions')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('bound_email')->nullable();
                $table->string('bound_password_hash', 255)->nullable();
                $table->string('created_ip', 45)->nullable();
                $table->string('used_ip', 45)->nullable();
                $table->text('used_user_agent')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
                $table->index('order_item_id');
            });
        }

        if (! Schema::hasTable('partner_email_change_requests')) {
            Schema::create('partner_email_change_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('old_email');
                $table->string('new_email');
                $table->string('confirm_hash', 64)->unique();
                $table->string('cancel_hash', 64)->unique();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_email_change_requests');
        Schema::dropIfExists('partner_email_action_tokens');
        if (Schema::hasTable('partners')) {
            Schema::table('partners', function (Blueprint $table) {
                if (Schema::hasColumn('partners', 'notification_preferences')) {
                    $table->dropColumn('notification_preferences');
                }
                if (Schema::hasColumn('partners', 'security_review_at')) {
                    $table->dropColumn('security_review_at');
                }
                if (Schema::hasColumn('partners', 'one_click_links')) {
                    $table->dropColumn('one_click_links');
                }
            });
        }
    }
};
