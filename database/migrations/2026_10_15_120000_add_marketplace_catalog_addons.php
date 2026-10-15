<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'link_guarantee')) {
                    $table->boolean('link_guarantee')->default(false);
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_fee')) {
                    $table->decimal('link_guarantee_fee', 10, 2)->default(0);
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_covered_amount')) {
                    $table->decimal('link_guarantee_covered_amount', 10, 2)->default(0);
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_status')) {
                    $table->string('link_guarantee_status', 32)->nullable();
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_starts_at')) {
                    $table->timestamp('link_guarantee_starts_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_ends_at')) {
                    $table->timestamp('link_guarantee_ends_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_restore_due_at')) {
                    $table->timestamp('link_guarantee_restore_due_at')->nullable();
                }
                if (! Schema::hasColumn('orders', 'link_guarantee_refunded_at')) {
                    $table->timestamp('link_guarantee_refunded_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'content_source')) {
                    $table->string('content_source', 20)->default('buyer');
                }
                if (! Schema::hasColumn('order_items', 'writing_fee')) {
                    $table->decimal('writing_fee', 10, 2)->default(0);
                }
                if (! Schema::hasColumn('order_items', 'listing_writing_fee')) {
                    $table->decimal('listing_writing_fee', 12, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                if (! Schema::hasColumn('sites', 'writing_enabled')) {
                    $table->boolean('writing_enabled')->default(true);
                }
                if (! Schema::hasColumn('sites', 'writing_fee')) {
                    $table->decimal('writing_fee', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('sites', 'listing_writing_fee')) {
                    $table->decimal('listing_writing_fee', 12, 2)->nullable();
                }
            });
        }

        if (! Schema::hasTable('link_checks')) {
            Schema::create('link_checks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('order_item_id')->index();
                $table->timestamp('checked_at');
                $table->string('status', 32);
                $table->unsignedSmallInteger('http_code')->nullable();
                $table->boolean('href_found')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('restore_due_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('saved_searches')) {
            Schema::create('saved_searches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 120);
                $table->json('query_json');
                $table->boolean('notify')->default(false);
                $table->timestamp('last_alerted_at')->nullable();
                $table->unsignedBigInteger('last_seen_max_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('publisher_reliability_stats')) {
            Schema::create('publisher_reliability_stats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('publisher_id')->unique();
                $table->unsignedInteger('sample_size')->default(0);
                $table->unsignedInteger('completed_orders')->default(0);
                $table->decimal('response_rate', 5, 2)->nullable();
                $table->decimal('avg_response_hours', 8, 2)->nullable();
                $table->decimal('publication_rate', 5, 2)->nullable();
                $table->decimal('avg_delivery_hours', 8, 2)->nullable();
                $table->boolean('badge_eligible')->default(false);
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_reliability_stats');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('link_checks');

        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                foreach (['writing_enabled', 'writing_fee', 'listing_writing_fee'] as $col) {
                    if (Schema::hasColumn('sites', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                foreach (['content_source', 'writing_fee', 'listing_writing_fee'] as $col) {
                    if (Schema::hasColumn('order_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach ([
                    'link_guarantee',
                    'link_guarantee_fee',
                    'link_guarantee_covered_amount',
                    'link_guarantee_status',
                    'link_guarantee_starts_at',
                    'link_guarantee_ends_at',
                    'link_guarantee_restore_due_at',
                    'link_guarantee_refunded_at',
                ] as $col) {
                    if (Schema::hasColumn('orders', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
