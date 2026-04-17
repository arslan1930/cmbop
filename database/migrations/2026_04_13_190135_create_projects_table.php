<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // Advertiser owner
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Project data
            $table->string('project_name');
            $table->string('project_url');


            $table->timestamps();

            // IMPORTANT: prevent duplicates per user
            $table->unique(['user_id', 'project_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};