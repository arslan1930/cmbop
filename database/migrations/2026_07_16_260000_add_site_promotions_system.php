<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'featured_until')) {
                $table->timestamp('featured_until')->nullable()->after('completed_orders_count')->index();
            }
            if (! Schema::hasColumn('sites', 'featured_purchased_at')) {
                $table->timestamp('featured_purchased_at')->nullable()->after('featured_until');
            }
            if (! Schema::hasColumn('sites', 'bulk_discount_enabled')) {
                $table->boolean('bulk_discount_enabled')->default(false)->after('featured_purchased_at')->index();
            }
            if (! Schema::hasColumn('sites', 'bulk_discount_percent')) {
                $table->decimal('bulk_discount_percent', 5, 2)->nullable()->after('bulk_discount_enabled');
            }
            if (! Schema::hasColumn('sites', 'custom_discount_percent')) {
                $table->decimal('custom_discount_percent', 5, 2)->nullable()->after('bulk_discount_percent');
            }
            if (! Schema::hasColumn('sites', 'custom_discount_starts_at')) {
                $table->timestamp('custom_discount_starts_at')->nullable()->after('custom_discount_percent');
            }
            if (! Schema::hasColumn('sites', 'custom_discount_ends_at')) {
                $table->timestamp('custom_discount_ends_at')->nullable()->after('custom_discount_starts_at')->index();
            }
            if (! Schema::hasColumn('sites', 'custom_discount_notified_at')) {
                $table->timestamp('custom_discount_notified_at')->nullable()->after('custom_discount_ends_at');
            }
        });

        if (! Schema::hasTable('site_feature_purchases')) {
            Schema::create('site_feature_purchases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('amount', 10, 2);
                $table->unsignedSmallInteger('days')->default(7);
                $table->string('payment_method', 40)->default('wallet');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_feature_purchases');

        Schema::table('sites', function (Blueprint $table) {
            foreach ([
                'custom_discount_notified_at',
                'custom_discount_ends_at',
                'custom_discount_starts_at',
                'custom_discount_percent',
                'bulk_discount_percent',
                'bulk_discount_enabled',
                'featured_purchased_at',
                'featured_until',
            ] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
