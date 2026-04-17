<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_role_id')
                  ->nullable()
                  ->constrained('roles')
                  ->nullOnDelete(); // if the role is deleted, set active_role_id to null
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['active_role_id']); // drop foreign key first
            $table->dropColumn('active_role_id');    // then drop the column
        });
    }
};