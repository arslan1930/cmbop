<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'listing_currency')) {
                $table->string('listing_currency', 3)->default('EUR');
            }
            if (! Schema::hasColumn('sites', 'listing_price')) {
                $table->decimal('listing_price', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('sites', 'listing_sensitive_prices')) {
                $table->json('listing_sensitive_prices')->nullable();
            }
            if (! Schema::hasColumn('sites', 'listing_homepage_prices')) {
                $table->json('listing_homepage_prices')->nullable();
            }
            if (! Schema::hasColumn('sites', 'listing_gbp_prompt_dismissed_at')) {
                $table->timestamp('listing_gbp_prompt_dismissed_at')->nullable();
            }
        });

        // Existing rows stay EUR until the publisher confirms a GBP switch.
        if (Schema::hasColumn('sites', 'listing_price') && Schema::hasColumn('sites', 'price')) {
            DB::table('sites')->whereNull('listing_price')->update([
                'listing_price' => DB::raw('price'),
                'listing_currency' => 'EUR',
            ]);
        }
        if (Schema::hasColumn('sites', 'listing_sensitive_prices') && Schema::hasColumn('sites', 'sensitive_prices')) {
            DB::table('sites')
                ->whereNull('listing_sensitive_prices')
                ->whereNotNull('sensitive_prices')
                ->update(['listing_sensitive_prices' => DB::raw('sensitive_prices')]);
        }
        if (Schema::hasColumn('sites', 'listing_homepage_prices') && Schema::hasColumn('sites', 'homepage_placement_prices')) {
            DB::table('sites')
                ->whereNull('listing_homepage_prices')
                ->whereNotNull('homepage_placement_prices')
                ->update(['listing_homepage_prices' => DB::raw('homepage_placement_prices')]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            foreach ([
                'listing_currency',
                'listing_price',
                'listing_sensitive_prices',
                'listing_homepage_prices',
                'listing_gbp_prompt_dismissed_at',
            ] as $column) {
                if (Schema::hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
