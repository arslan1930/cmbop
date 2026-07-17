<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('type', 40)->default('general'); // discount|black_friday|offer|change|general|maintenance
            $table->string('style', 40)->default('info'); // info|success|warning|danger|promo
            $table->string('audience', 40)->default('all'); // all|public|advertiser|publisher
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'audience', 'priority']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('ad_banners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('size_key', 40); // leaderboard|medium_rectangle|...
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('link_url')->nullable();
            $table->string('placement', 40)->default('content_top');
            $table->string('audience', 40)->default('all'); // all|public|advertiser|publisher
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'placement', 'audience', 'priority']);
            $table->index(['size_key', 'is_active']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_banners');
        Schema::dropIfExists('site_announcements');
    }
};
