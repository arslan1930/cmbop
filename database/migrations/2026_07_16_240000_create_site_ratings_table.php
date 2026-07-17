<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->string('comment', 500)->nullable();
            $table->string('status', 20)->default('approved')->index(); // approved|hidden|pending
            $table->boolean('is_admin')->default(false); // created/adjusted by admin
            $table->timestamps();

            $table->unique(['site_id', 'user_id']);
            $table->index(['site_id', 'status']);
        });

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'rating_avg')) {
                $table->decimal('rating_avg', 3, 2)->default(0)->after('verified');
            }
            if (! Schema::hasColumn('sites', 'rating_count')) {
                $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            foreach (['rating_count', 'rating_avg'] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('site_ratings');
    }
};
