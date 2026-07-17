<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class EmailNotificationPreference extends Model
{
    protected $fillable = ['user_id', 'preference_key', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function allows(?User $user, ?string $preferenceKey): bool
    {
        if (!$preferenceKey) {
            return true;
        }

        $meta = config("email_notifications.preference_keys.{$preferenceKey}", []);
        if (!empty($meta['locked'])) {
            return true; // security always on
        }

        $default = (bool) ($meta['default'] ?? true);
        if (!$user || !$user->id) {
            return $default;
        }

        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return $default;
            }

            $row = static::query()
                ->where('user_id', $user->id)
                ->where('preference_key', $preferenceKey)
                ->first();

            return $row ? (bool) $row->enabled : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function forUser(User $user): array
    {
        $keys = config('email_notifications.preference_keys', []);
        $stored = static::query()
            ->where('user_id', $user->id)
            ->pluck('enabled', 'preference_key');

        $out = [];
        foreach ($keys as $key => $meta) {
            $out[$key] = [
                'key' => $key,
                'label' => $meta['label'] ?? $key,
                'locked' => (bool) ($meta['locked'] ?? false),
                'enabled' => array_key_exists($key, $stored->all())
                    ? (bool) $stored[$key]
                    : (bool) ($meta['default'] ?? true),
            ];
        }

        return $out;
    }
}
