<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The blogs feature (public blog + admin CRUD) shipped without a migration,
 * so fresh environments and CI had no `blogs` table. This creates it when
 * missing and backfills any columns older databases may lack.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs')) {
            Schema::create('blogs', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('excerpt')->nullable();
                $table->longText('content');
                $table->string('featured_image')->nullable();
                $table->string('author')->nullable();
                $table->text('tags')->nullable();
                $table->string('status', 20)->default('draft')->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            return;
        }

        Schema::table('blogs', function (Blueprint $table) {
            if (! Schema::hasColumn('blogs', 'excerpt')) {
                $table->string('excerpt')->nullable()->after('slug');
            }
            if (! Schema::hasColumn('blogs', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
