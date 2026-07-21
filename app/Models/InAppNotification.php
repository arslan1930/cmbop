<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InAppNotification extends Model
{
    use SoftDeletes;

    public const STATUS_UNREAD = 'unread';

    public const STATUS_READ = 'read';

    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_ADVERTISER = 'advertiser';

    public const AUDIENCE_PUBLISHER = 'publisher';

    public const AUDIENCE_ADMIN = 'admin';

    protected $table = 'in_app_notifications';

    protected $fillable = [
        'user_id',
        'audience',
        'type',
        'category',
        'title',
        'message',
        'icon',
        'priority',
        'status',
        'related_type',
        'related_id',
        'action_label',
        'action_url',
        'meta',
        'read_at',
        'archived_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'read_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Limit to notifications intended for the active marketplace role.
     * Staff roles (admin/marketing) see everything for the user.
     */
    public function scopeForAudience($query, ?string $role)
    {
        $role = $role ? strtolower(trim($role)) : null;
        if (! $role || in_array($role, ['admin', 'marketing'], true)) {
            return $query;
        }

        return $query->where(function ($q) use ($role) {
            $q->where('audience', $role)
                ->orWhere('audience', self::AUDIENCE_ALL)
                ->orWhereNull('audience');
        });
    }

    public function scopeVisible($query)
    {
        return $query->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhere('status', '!=', self::STATUS_ARCHIVED);
            })
            ->whereNull('archived_at');
    }

    public function scopeUnread($query)
    {
        return $query->where('status', self::STATUS_UNREAD);
    }

    public function markRead(): self
    {
        if ($this->status !== self::STATUS_READ) {
            $this->forceFill([
                'status' => self::STATUS_READ,
                'read_at' => now(),
            ])->save();
        }

        return $this;
    }

    public function archive(): self
    {
        $this->forceFill([
            'status' => self::STATUS_ARCHIVED,
            'archived_at' => now(),
            'read_at' => $this->read_at ?? now(),
        ])->save();

        return $this;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'audience' => $this->audience ?: self::AUDIENCE_ALL,
            'type' => $this->type,
            'category' => $this->category,
            'title' => $this->title,
            'message' => $this->message,
            'icon' => $this->icon,
            'priority' => $this->priority,
            'status' => $this->status,
            'is_unread' => $this->status === self::STATUS_UNREAD,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'action_label' => $this->action_label ?: 'View details',
            'action_url' => $this->action_url,
            'meta' => $this->meta ?? [],
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'read_at' => optional($this->read_at)?->toIso8601String(),
        ];
    }
}
