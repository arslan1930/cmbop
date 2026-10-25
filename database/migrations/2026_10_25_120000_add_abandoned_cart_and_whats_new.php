<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abandoned_cart_settings')) {
            Schema::create('abandoned_cart_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('abandoned_cart_reminder_sends')) {
            Schema::create('abandoned_cart_reminder_sends', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('cart_fingerprint', 64);
                $table->unsignedTinyInteger('stage');
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'cart_fingerprint', 'stage'], 'abandoned_cart_sends_user_fp_stage');
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('announcement_dismissals')) {
            Schema::create('announcement_dismissals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'announcement_id'], 'announcement_dismissals_user_announcement');
                $table->index('announcement_id');
            });
        }

        if (Schema::hasTable('email_campaigns')) {
            Schema::table('email_campaigns', function (Blueprint $table) {
                if (! Schema::hasColumn('email_campaigns', 'template_key')) {
                    $table->string('template_key', 40)->nullable();
                }
                if (! Schema::hasColumn('email_campaigns', 'template_payload')) {
                    $table->json('template_payload')->nullable();
                }
                if (! Schema::hasColumn('email_campaigns', 'preference_key')) {
                    $table->string('preference_key', 40)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_campaigns')) {
            Schema::table('email_campaigns', function (Blueprint $table) {
                if (Schema::hasColumn('email_campaigns', 'preference_key')) {
                    $table->dropColumn('preference_key');
                }
                if (Schema::hasColumn('email_campaigns', 'template_payload')) {
                    $table->dropColumn('template_payload');
                }
                if (Schema::hasColumn('email_campaigns', 'template_key')) {
                    $table->dropColumn('template_key');
                }
            });
        }

        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('abandoned_cart_reminder_sends');
        Schema::dropIfExists('abandoned_cart_settings');
    }
};
