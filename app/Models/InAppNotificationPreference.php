<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user in-app (bell) category toggles.
 * Table may lag deploy — every caller must tolerate a missing table.
 */
class InAppNotificationPreference extends Model
{
    public const CATEGORIES = ['orders', 'messages', 'payments', 'account'];

    protected static ?bool $tableAvailable = null;

    protected $table = 'in_app_notification_preferences';

    protected $fillable = [
        'user_id',
        'category',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public static function tableAvailable(): bool
    {
        if (static::$tableAvailable !== null) {
            return static::$tableAvailable;
        }

        try {
            return static::$tableAvailable = Schema::hasTable('in_app_notification_preferences');
        } catch (\Throwable) {
            return static::$tableAvailable = false;
        }
    }

    /** @internal */
    public static function forgetTableAvailabilityCache(): void
    {
        static::$tableAvailable = null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the user wants bell notifications for this category.
     * Defaults to true when the preferences table is missing or no row exists.
     */
    public static function allows(int $userId, string $category): bool
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'system' || $category === 'support') {
            // System / support bells always deliver.
            return true;
        }

        if (! static::tableAvailable()) {
            return true;
        }

        try {
            $row = static::query()
                ->where('user_id', $userId)
                ->where('category', $category)
                ->first();

            if (! $row) {
                return true;
            }

            return (bool) $row->enabled;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    public static function forUser(User $user): array
    {
        $labels = [
            'orders' => 'Orders',
            'messages' => 'Messages',
            'payments' => 'Payments',
            'account' => 'Account',
        ];

        $stored = [];
        if (static::tableAvailable()) {
            try {
                $stored = static::query()
                    ->where('user_id', $user->id)
                    ->pluck('enabled', 'category')
                    ->all();
            } catch (\Throwable) {
                $stored = [];
            }
        }

        $out = [];
        foreach ($labels as $key => $label) {
            $out[] = [
                'key' => $key,
                'label' => $label,
                'enabled' => array_key_exists($key, $stored) ? (bool) $stored[$key] : true,
                'available' => static::tableAvailable(),
            ];
        }

        return $out;
    }
}
