<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BillingRuleSetting extends Model
{
    public const KEY_CONFIG = 'config';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Same schema as 2026_09_14_213000. Used by Finance payout rules when
     * migrate never created the table (Hostinger leftover). Not called from
     * publisher withdraw — reads fall back to config when the table is gone.
     */
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
            Log::warning('Could not create billing_rule_settings at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function minAmountMax(): float
    {
        return round(max(0.01, (float) config('billing.withdrawal_min_amount_max', 10000)), 2);
    }

    public static function feePercentMax(): float
    {
        return round(max(0, min(100, (float) config('billing.withdrawal_fee_percent_max', 50))), 2);
    }

    public static function normalizeMinAmount(mixed $amount): float
    {
        if (! is_numeric($amount)) {
            return static::normalizeMinAmount(config('billing.withdrawal_min_amount', 20));
        }

        return round(max(0.01, min((float) $amount, static::minAmountMax())), 2);
    }

    public static function normalizeFeePercent(mixed $percent): float
    {
        if (! is_numeric($percent)) {
            return static::normalizeFeePercent(config('billing.withdrawal_fee_percent', 0));
        }

        return round(max(0, min((float) $percent, static::feePercentMax())), 2);
    }

    public static function minAmount(): float
    {
        $stored = static::storedNumeric('min_amount');
        if ($stored !== null) {
            return static::normalizeMinAmount($stored);
        }

        return static::normalizeMinAmount(config('billing.withdrawal_min_amount', 20));
    }

    public static function feePercent(): float
    {
        $stored = static::storedNumeric('fee_percent');
        if ($stored !== null) {
            return static::normalizeFeePercent($stored);
        }

        return static::normalizeFeePercent(config('billing.withdrawal_fee_percent', 0));
    }

    public static function hasStoredMinAmount(): bool
    {
        return static::storedNumeric('min_amount') !== null;
    }

    public static function hasStoredFeePercent(): bool
    {
        return static::storedNumeric('fee_percent') !== null;
    }

    public static function setMinAmount(float $amount, ?int $updatedBy = null): void
    {
        static::patchConfig(['min_amount' => static::normalizeMinAmount($amount)], $updatedBy);
    }

    public static function setFeePercent(float $percent, ?int $updatedBy = null): void
    {
        static::patchConfig(['fee_percent' => static::normalizeFeePercent($percent)], $updatedBy);
    }

    /**
     * @param  array{min_amount?: float, fee_percent?: float}  $patch
     */
    public static function patchConfig(array $patch, ?int $updatedBy = null): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $write = function () use ($patch, $updatedBy): void {
            DB::transaction(function () use ($patch, $updatedBy) {
                $row = static::query()->where('key', self::KEY_CONFIG)->orderBy('id')->lockForUpdate()->first();
                try {
                    $current = is_array($row?->value) ? $row->value : [];
                } catch (\Throwable) {
                    $current = [];
                }

                if (array_key_exists('min_amount', $patch)) {
                    $current['min_amount'] = static::normalizeMinAmount($patch['min_amount']);
                }
                if (array_key_exists('fee_percent', $patch)) {
                    $current['fee_percent'] = static::normalizeFeePercent($patch['fee_percent']);
                }
                $current['updated_at'] = now()->toIso8601String();
                if ($updatedBy !== null) {
                    $current['updated_by'] = $updatedBy;
                }

                if ($row === null) {
                    static::query()->create(['key' => self::KEY_CONFIG, 'value' => $current]);
                } else {
                    $row->value = $current;
                    $row->save();
                    static::query()->where('key', self::KEY_CONFIG)->where('id', '!=', $row->id)->delete();
                }
                Cache::forget('billing_rule_setting:config');
            });
        };

        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            $write();
        }
    }

    public static function clearCache(): void
    {
        Cache::forget('billing_rule_setting:config');
    }

    private static function storedNumeric(string $field): ?float
    {
        $stored = static::storedConfig();
        if (! is_array($stored) || ! array_key_exists($field, $stored) || ! is_numeric($stored[$field])) {
            return null;
        }

        return (float) $stored[$field];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function storedConfig(): ?array
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return null;
            }

            $row = static::query()->where('key', self::KEY_CONFIG)->orderBy('id')->first();
            $value = $row?->value;

            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
