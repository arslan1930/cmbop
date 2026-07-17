<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id')->nullable()->unique()->after('google_refresh_token');
            $table->text('apple_token')->nullable()->after('apple_id');
            $table->text('apple_refresh_token')->nullable()->after('apple_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['apple_id', 'apple_token', 'apple_refresh_token']);
        });
    }
};
