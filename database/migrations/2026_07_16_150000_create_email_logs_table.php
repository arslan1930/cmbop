<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->nullable()->index();
            $table->string('mailable')->nullable()->index();
            $table->string('template_key')->nullable()->index();
            $table->string('to_email')->index();
            $table->string('to_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 32)->default('pending')->index(); // pending|delivered|failed
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
