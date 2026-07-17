<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class EmailNotificationSetting extends Model
{
    protected $fillable = ['type', 'enabled', 'subject_override'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public static function isEnabled(string $type): bool
    {
        $defaults = (bool) config("email_notifications.types.{$type}.default_enabled", true);

        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return $defaults;
            }

            return Cache::remember("email_setting_enabled_{$type}", 60, function () use ($type, $defaults) {
                $row = static::query()->where('type', $type)->first();
                if (! $row) {
                    return $defaults;
                }

                return (bool) $row->enabled;
            });
        } catch (\Throwable) {
            return $defaults;
        }
    }

    public static function flushCache(?string $type = null): void
    {
        if ($type) {
            Cache::forget("email_setting_enabled_{$type}");
            return;
        }

        foreach (array_keys(config('email_notifications.types', [])) as $key) {
            Cache::forget("email_setting_enabled_{$key}");
        }
    }
}
