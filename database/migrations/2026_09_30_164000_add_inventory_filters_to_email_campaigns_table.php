<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_campaigns')
            || Schema::hasColumn('email_campaigns', 'inventory_filters')) {
            return;
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->json('inventory_filters')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_campaigns')
            || ! Schema::hasColumn('email_campaigns', 'inventory_filters')) {
            return;
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('inventory_filters');
        });
    }
};
