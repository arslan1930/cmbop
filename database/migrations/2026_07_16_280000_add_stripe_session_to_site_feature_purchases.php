<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_feature_purchases')) {
            return;
        }

        Schema::table('site_feature_purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('site_feature_purchases', 'stripe_session_id')) {
                $table->string('stripe_session_id')->nullable()->after('payment_method')->index();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('site_feature_purchases') && Schema::hasColumn('site_feature_purchases', 'stripe_session_id')) {
            Schema::table('site_feature_purchases', function (Blueprint $table) {
                $table->dropColumn('stripe_session_id');
            });
        }
    }
};
