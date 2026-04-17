<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            
            // Link wallet to user
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Link wallet to role
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            
            // Balances
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('reserved_balance', 12, 2)->default(0);
            
            $table->string('currency', 3)->default('EUR');
            
            $table->timestamps();

            // Ensure one wallet per user-role combination
            $table->unique(['user_id','role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
