<?php
// database/migrations/2024_01_01_000001_create_order_chat_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderChatMessagesTable extends Migration
{
    public function up()
    {
        Schema::create('order_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('sender_type', ['advertiser', 'publisher']);
            $table->longText('message')->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['order_id', 'created_at']);
            $table->index(['user_id', 'is_read']);
            $table->index(['sender_type', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_chat_messages');
    }
}