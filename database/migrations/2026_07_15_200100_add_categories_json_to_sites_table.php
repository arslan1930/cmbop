<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (!Schema::hasColumn('sites', 'categories')) {
                $table->json('categories')->nullable()->after('category');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'categories')) {
                $table->dropColumn('categories');
            }
        });
    }
};
