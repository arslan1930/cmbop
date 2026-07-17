<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64); // message, order_created, payment_received, system, ...
            $table->string('category', 32)->default('system'); // orders, messages, payments, system, support, account
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('icon', 64)->nullable(); // lucide icon name or emoji key
            $table->string('priority', 16)->default('normal'); // low, normal, high, urgent
            $table->string('status', 16)->default('unread'); // unread, read, archived
            $table->string('related_type')->nullable(); // App\Models\Order, etc.
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('action_label')->nullable();
            $table->string('action_url', 1024)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['user_id', 'category', 'created_at']);
            $table->index(['related_type', 'related_id']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
