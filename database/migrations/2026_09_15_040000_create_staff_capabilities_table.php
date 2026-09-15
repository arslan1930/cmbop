<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_capabilities')) {
            return;
        }

        Schema::create('staff_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('capability', 32);
            $table->timestamps();
            $table->unique(['user_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_capabilities');
    }
};
