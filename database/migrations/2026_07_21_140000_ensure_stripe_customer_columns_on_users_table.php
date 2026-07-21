<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent safety net for environments (e.g. Hostinger) where
 * 2026_07_18_120000 may be recorded as ran without the columns existing,
 * or where artisan migrate was never applied after the saved-cards deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $needsCustomer = ! Schema::hasColumn('users', 'stripe_customer_id');
        $needsDefaultPm = ! Schema::hasColumn('users', 'stripe_default_payment_method_id');

        if (! $needsCustomer && ! $needsDefaultPm) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($needsCustomer, $needsDefaultPm) {
            if ($needsCustomer) {
                $table->string('stripe_customer_id')->nullable()->unique();
            }
            if ($needsDefaultPm) {
                $table->string('stripe_default_payment_method_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty — do not drop columns that may be in use.
    }
};
