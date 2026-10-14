<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'advertiser_eur_amount')) {
                $table->decimal('advertiser_eur_amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'publisher_amount')) {
                $table->decimal('publisher_amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'publisher_currency')) {
                $table->string('publisher_currency', 3)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'fx_rate')) {
                $table->decimal('fx_rate', 16, 8)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'fx_buffer_percent')) {
                $table->decimal('fx_buffer_percent', 5, 2)->nullable();
            }
        });

        $this->backfillEurRows();
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'advertiser_eur_amount',
                'publisher_amount',
                'publisher_currency',
                'fx_rate',
                'fx_buffer_percent',
            ] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillEurRows(): void
    {
        if (! Schema::hasColumn('order_items', 'publisher_amount')) {
            return;
        }

        $hasPublisherPrice = Schema::hasColumn('order_items', 'publisher_price');
        $hasAdditional = Schema::hasColumn('order_items', 'additional_price');
        $hasHomepage = Schema::hasColumn('order_items', 'homepage_price');

        $publisherExpr = $hasPublisherPrice
            ? 'COALESCE(publisher_price, ROUND((price'
                .($hasAdditional ? ' - COALESCE(additional_price, 0)' : '')
                .($hasHomepage ? ' - COALESCE(homepage_price, 0)' : '')
                .') / 1.15, 2))'
                .($hasAdditional ? ' + COALESCE(additional_price, 0)' : '')
                .($hasHomepage ? ' + COALESCE(homepage_price, 0)' : '')
            : 'price';

        DB::table('order_items')
            ->whereNull('publisher_currency')
            ->update([
                'advertiser_eur_amount' => DB::raw('price'),
                'publisher_amount' => DB::raw($publisherExpr),
                'publisher_currency' => 'EUR',
            ]);
    }
};
