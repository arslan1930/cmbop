<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FeatureOfferSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function ensureTable(): void
    {
        try {
            if (Schema::hasTable((new static)->getTable())) {
                return;
            }

            Schema::create((new static)->getTable(), function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            Log::warning('Could not create feature_offer_settings at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, array{label:string, price:float, days:int, active:bool}>
     */
    public static function defaults(): array
    {
        return [
            'month' => ['label' => 'Monthly', 'price' => 15.0, 'days' => 30, 'active' => true],
            'year' => ['label' => 'Yearly', 'price' => 100.0, 'days' => 365, 'active' => true],
        ];
    }

    /**
     * @return array<string, array{key:string, label:string, price:float, days:int, active:bool}>
     */
    public static function offers(): array
    {
        self::ensureTable();
        $stored = [];
        if (Schema::hasTable((new static)->getTable())) {
            try {
                $row = static::query()->where('key', 'packages')->orderBy('id')->first();
                $stored = is_array($row?->value) ? $row->value : [];
            } catch (\Throwable) {
                $stored = [];
            }
        }

        $offers = [];
        foreach (self::defaults() as $key => $fallback) {
            $row = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $offers[$key] = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? $fallback['label']),
                'price' => round(max(0.5, min(5000, (float) ($row['price'] ?? $fallback['price']))), 2),
                'days' => max(1, min(400, (int) ($row['days'] ?? $fallback['days']))),
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
            ];
        }

        return $offers;
    }

    /**
     * @param  array<string, array{label?:string, price?:float|int|string, days?:int|string, active?:bool}>  $packages
     */
    public static function saveOffers(array $packages): void
    {
        self::ensureTable();
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $current = self::offers();
        foreach ($packages as $key => $row) {
            if (! isset($current[$key]) || ! is_array($row)) {
                continue;
            }
            $current[$key]['label'] = trim((string) ($row['label'] ?? $current[$key]['label'])) ?: $current[$key]['label'];
            $current[$key]['price'] = round(max(0.5, min(5000, (float) ($row['price'] ?? $current[$key]['price']))), 2);
            $current[$key]['days'] = max(1, min(400, (int) ($row['days'] ?? $current[$key]['days'])));
            $current[$key]['active'] = (bool) ($row['active'] ?? $current[$key]['active']);
        }

        $value = [];
        foreach ($current as $key => $offer) {
            $value[$key] = [
                'label' => $offer['label'],
                'price' => $offer['price'],
                'days' => $offer['days'],
                'active' => $offer['active'],
            ];
        }

        $row = static::query()->where('key', 'packages')->orderBy('id')->first();
        if ($row === null) {
            static::query()->create(['key' => 'packages', 'value' => $value]);
        } else {
            $row->value = $value;
            $row->save();
        }
    }
}
