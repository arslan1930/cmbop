<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('publisher_price', 10, 2)->nullable()->after('additional_price');
            $table->decimal('platform_fee_percent', 5, 2)->nullable()->after('publisher_price');
            $table->decimal('platform_fee_amount', 10, 2)->nullable()->after('platform_fee_percent');
        });

        // Backfill legacy rows that used the historical flat 15% markup.
        $rate = (float) (config('pricing.legacy_markup_rate') ?: 1.15);
        DB::table('order_items')
            ->whereNull('publisher_price')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($rate) {
                foreach ($rows as $row) {
                    $additional = (float) ($row->additional_price ?? 0);
                    $markedUpBase = round((float) $row->price - $additional, 2);
                    $publisherPrice = round($markedUpBase / $rate, 2);
                    $feeAmount = round($markedUpBase - $publisherPrice, 2);
                    $feePercent = $publisherPrice > 0
                        ? round(($feeAmount / $publisherPrice) * 100, 2)
                        : 15.0;

                    DB::table('order_items')->where('id', $row->id)->update([
                        'publisher_price' => $publisherPrice,
                        'platform_fee_percent' => $feePercent,
                        'platform_fee_amount' => $feeAmount,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['publisher_price', 'platform_fee_percent', 'platform_fee_amount']);
        });
    }
};
