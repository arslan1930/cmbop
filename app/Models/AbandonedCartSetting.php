<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AbandonedCartSetting extends Model
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
            Log::warning('Could not create abandoned_cart_settings at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function isEnabled(): bool
    {
        $envDefault = (bool) config('reminders.abandoned_cart.enabled', false);

        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return $envDefault;
            }

            $row = static::query()->where('key', 'config')->orderBy('id')->first();
            if (! $row || ! is_array($row->value) || ! array_key_exists('enabled', $row->value)) {
                return $envDefault;
            }

            $raw = $row->value['enabled'];
            if (is_bool($raw)) {
                return $raw;
            }

            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setEnabled(bool $enabled, ?int $updatedBy = null): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $payload = [
            'enabled' => $enabled,
            'updated_at' => now()->toIso8601String(),
        ];
        if ($updatedBy !== null) {
            $payload['updated_by'] = $updatedBy;
        }

        $row = static::query()->where('key', 'config')->orderBy('id')->first();
        if ($row === null) {
            static::query()->create(['key' => 'config', 'value' => $payload]);
        } else {
            $current = is_array($row->value) ? $row->value : [];
            $row->value = array_merge($current, $payload);
            $row->save();
        }

        Cache::forget('abandoned_cart_setting:config');
    }
}
