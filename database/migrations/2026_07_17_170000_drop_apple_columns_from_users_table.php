<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'apple_id')) {
            return;
        }

        // Drop unique index before the column (required for SQLite).
        try {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['apple_id']);
            });
        } catch (\Throwable) {
            // Index may already be absent or named differently on some drivers.
        }

        $columns = array_values(array_filter(
            ['apple_id', 'apple_token', 'apple_refresh_token'],
            fn (string $column) => Schema::hasColumn('users', $column)
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'apple_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id')->nullable()->unique();
            $table->text('apple_token')->nullable();
            $table->text('apple_refresh_token')->nullable();
        });
    }
};
