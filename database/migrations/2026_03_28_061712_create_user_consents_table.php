<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('user_consents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();

        $table->boolean('terms_accepted')->default(false);
        $table->boolean('marketing_consent')->default(false);
        $table->boolean('newsletter_consent')->default(false);

        // Optional but VERY useful (GDPR proof)
        $table->timestamp('consented_at')->nullable();
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
