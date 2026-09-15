<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legal_page_overrides')) {
            return;
        }

        Schema::create('legal_page_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64);
            $table->string('locale', 8);
            $table->string('title')->nullable();
            $table->mediumText('body_html');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['slug', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_page_overrides');
    }
};
