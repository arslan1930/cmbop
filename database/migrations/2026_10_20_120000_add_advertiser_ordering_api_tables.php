<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('advertiser_api_tokens')) {
            Schema::table('advertiser_api_tokens', function (Blueprint $table) {
                if (! Schema::hasColumn('advertiser_api_tokens', 'scopes')) {
                    $table->json('scopes')->nullable()->after('token_hash');
                }
                if (! Schema::hasColumn('advertiser_api_tokens', 'mode')) {
                    $table->string('mode', 8)->default('live')->after('scopes');
                }
                if (! Schema::hasColumn('advertiser_api_tokens', 'last_used_ip')) {
                    $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
                }
                if (! Schema::hasColumn('advertiser_api_tokens', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable()->after('last_used_ip');
                }
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'is_sandbox')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('is_sandbox')->default(false)->index();
            });
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'is_sandbox')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->boolean('is_sandbox')->default(false)->index();
            });
        }

        if (! Schema::hasTable('advertiser_webhook_endpoints')) {
            Schema::create('advertiser_webhook_endpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('url', 2000);
                $table->json('events')->nullable();
                $table->text('secret_encrypted');
                $table->string('status', 20)->default('enabled');
                $table->unsignedInteger('failure_streak')->default(0);
                $table->timestamp('disabled_at')->nullable();
                $table->string('description')->nullable();
                $table->timestamps();
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('advertiser_webhook_events')) {
            Schema::create('advertiser_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('public_id', 32)->unique();
                $table->string('type', 80);
                $table->json('payload');
                $table->boolean('livemode')->default(true);
                $table->timestamps();
                $table->index(['user_id', 'type']);
            });
        }

        if (! Schema::hasTable('advertiser_webhook_deliveries')) {
            Schema::create('advertiser_webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('endpoint_id');
                $table->unsignedInteger('attempt')->default(0);
                $table->string('status', 20)->default('pending');
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'next_attempt_at']);
            });
        }

        if (! Schema::hasTable('advertiser_idempotency_keys')) {
            Schema::create('advertiser_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('key', 191);
                $table->string('request_hash', 64);
                $table->unsignedSmallInteger('response_code');
                $table->longText('response_body');
                $table->timestamps();
                $table->unique(['user_id', 'key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advertiser_idempotency_keys');
        Schema::dropIfExists('advertiser_webhook_deliveries');
        Schema::dropIfExists('advertiser_webhook_events');
        Schema::dropIfExists('advertiser_webhook_endpoints');

        if (Schema::hasTable('advertiser_api_tokens')) {
            Schema::table('advertiser_api_tokens', function (Blueprint $table) {
                foreach (['scopes', 'mode', 'last_used_ip', 'revoked_at'] as $column) {
                    if (Schema::hasColumn('advertiser_api_tokens', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
