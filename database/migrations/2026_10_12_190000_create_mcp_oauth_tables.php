<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mcp_oauth_clients')) {
            Schema::create('mcp_oauth_clients', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->string('name', 255);
                $table->json('redirect_uris');
                $table->string('token_endpoint_auth_method', 40)->default('none');
                $table->json('grant_types')->nullable();
                $table->string('logo_uri', 2048)->nullable();
                $table->string('client_uri', 2048)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mcp_oauth_auth_codes')) {
            Schema::create('mcp_oauth_auth_codes', function (Blueprint $table) {
                $table->id();
                $table->string('client_id', 64);
                $table->unsignedBigInteger('user_id');
                $table->string('code_hash', 64)->unique();
                $table->string('code_challenge', 128);
                $table->string('code_challenge_method', 16)->default('S256');
                $table->string('redirect_uri', 2048);
                $table->string('scopes', 255)->default('mcp:use');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['client_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('mcp_oauth_access_tokens')) {
            Schema::create('mcp_oauth_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('client_id', 64);
                $table->unsignedBigInteger('user_id');
                $table->string('token_prefix', 16);
                $table->string('token_hash', 64)->unique();
                $table->string('refresh_token_hash', 64)->nullable()->unique();
                $table->string('scopes', 255)->default('mcp:use');
                $table->timestamp('expires_at');
                $table->timestamp('refresh_expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'client_id']);
            });
        }

        if (! Schema::hasTable('advertiser_saved_cart_items')) {
            Schema::create('advertiser_saved_cart_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('site_id');
                $table->unsignedTinyInteger('quantity')->default(1);
                $table->timestamps();

                $table->unique(['user_id', 'site_id']);
                $table->index('site_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advertiser_saved_cart_items');
        Schema::dropIfExists('mcp_oauth_access_tokens');
        Schema::dropIfExists('mcp_oauth_auth_codes');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
