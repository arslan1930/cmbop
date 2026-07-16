<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->string('event', 64); // order.created, order.accepted, ...
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('badge_color', 32)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_activities');
    }
};
