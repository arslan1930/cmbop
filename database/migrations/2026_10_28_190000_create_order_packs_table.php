<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Article packs: N (3–10) placements on one site bought in one checkout.
 * orders.order_pack_id is nullable so checkout still works before the SQL
 * twin is applied on Hostinger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_packs')) {
            Schema::create('order_packs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('site_id')->nullable();
                $table->string('reference_code', 32)->nullable()->index();
                $table->unsignedTinyInteger('pack_size');
                $table->unsignedTinyInteger('discount_tier')->nullable();
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->string('status', 40)->default('in_progress')->index();
                $table->string('payment_method', 40)->nullable();
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->unsignedTinyInteger('published_count')->default(0);
                $table->unsignedTinyInteger('refunded_count')->default(0);
                $table->unsignedTinyInteger('completed_count')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['site_id', 'reference_code']);
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'order_pack_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('order_pack_id')->nullable()->after('reference_code')->index();
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'order_pack_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('order_pack_id')->nullable()->after('order_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'order_pack_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('order_pack_id');
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'order_pack_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('order_pack_id');
            });
        }

        Schema::dropIfExists('order_packs');
    }
};
