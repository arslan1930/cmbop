<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ContentModerationSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember('content_moderation_setting:' . $key, 60, function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row?->value ?? $default;
        });
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('content_moderation_setting:' . $key);
        Cache::forget('content_moderation_effective_config');
    }

    public static function clearCache(): void
    {
        Cache::forget('content_moderation_effective_config');
        foreach (static::query()->pluck('key') as $key) {
            Cache::forget('content_moderation_setting:' . $key);
        }
    }
}
