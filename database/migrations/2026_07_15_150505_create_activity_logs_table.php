<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable(); // snapshot of actor name at time of action
            $table->string('user_email')->nullable();
            $table->string('role')->nullable(); // active role when action happened
            $table->string('action'); // e.g. site.verified, site.updated
            $table->string('subject_type')->nullable(); // e.g. App\Models\Site
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable(); // human label e.g. site name / URL
            $table->text('description')->nullable();
            $table->json('properties')->nullable(); // before/after / extra meta
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
