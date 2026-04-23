<?php
// database/migrations/xxxx_add_stripe_fields_to_deposit_requests_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->string('stripe_session_id')->nullable()->after('reference_code');
            $table->string('stripe_payment_intent_id')->nullable()->after('stripe_session_id');
            $table->json('stripe_response')->nullable()->after('stripe_payment_intent_id');
            $table->timestamp('paid_at')->nullable()->after('stripe_response');
        });
    }

    public function down()
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropColumn(['stripe_session_id', 'stripe_payment_intent_id', 'stripe_response', 'paid_at']);
        });
    }
};