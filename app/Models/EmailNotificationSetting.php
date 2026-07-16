<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class EmailNotificationSetting extends Model
{
    protected $fillable = ['type', 'enabled', 'subject_override'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public static function isEnabled(string $type): bool
    {
        $defaults = config("email_notifications.types.{$type}.default_enabled", true);

        return Cache::remember("email_setting_enabled_{$type}", 60, function () use ($type, $defaults) {
            $row = static::query()->where('type', $type)->first();
            if (!$row) {
                return (bool) $defaults;
            }

            return (bool) $row->enabled;
        });
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
