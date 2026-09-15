<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_two_factor')) {
            return;
        }

        Schema::create('staff_two_factor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret')->nullable();
            $table->text('recovery_codes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_totp_step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_two_factor');
    }
};
