<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementDismissal extends Model
{
    use ToleratesMissingSchema;

    protected $fillable = [
        'user_id',
        'announcement_id',
        'version',
        'dismissed_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(SiteAnnouncement::class, 'announcement_id');
    }

    public static function isDismissed(int $userId, int $announcementId, int $version): bool
    {
        if (! static::tableAvailable()) {
            return false;
        }

        try {
            $row = static::query()
                ->where('user_id', $userId)
                ->where('announcement_id', $announcementId)
                ->first();
        } catch (\Throwable) {
            return false;
        }

        if (! $row) {
            return false;
        }

        return (int) $row->version >= max(1, $version);
    }

    public static function dismiss(int $userId, int $announcementId, int $version): void
    {
        if (! static::tableAvailable()) {
            return;
        }

        try {
            static::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'announcement_id' => $announcementId,
                ],
                [
                    'version' => max(1, $version),
                    'dismissed_at' => now(),
                ]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<int, int> announcement_id => dismissed version
     */
    public static function versionsForUser(int $userId): array
    {
        if (! static::tableAvailable()) {
            return [];
        }

        try {
            return static::query()
                ->where('user_id', $userId)
                ->pluck('version', 'announcement_id')
                ->map(fn ($version) => (int) $version)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
