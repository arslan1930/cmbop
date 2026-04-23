<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stripe_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->json('payload');
            $table->boolean('processed')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('stripe_webhook_logs');
    }
};