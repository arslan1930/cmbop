<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('type', 40); // deposit, bonus_credit, purchase, refund, withdrawal, adjustment, transfer_out, transfer_in
            $table->string('direction', 10); // credit, debit
            $table->decimal('amount', 12, 2);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->decimal('bonus_balance_after', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 40)->default('completed');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->nullableMorphs('related');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type', 'status']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
