<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InAppNotification extends Model
{
    use SoftDeletes;
    use ToleratesUnparseableDates;

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

    protected static ?bool $tableAvailable = null;

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

    /**
     * True when in_app_notifications exists (migration may lag deploy).
     */
    public static function tableAvailable(): bool
    {
        if (static::$tableAvailable !== null) {
            return static::$tableAvailable;
        }

        try {
            return static::$tableAvailable = Schema::hasTable('in_app_notifications');
        } catch (\Throwable) {
            return static::$tableAvailable = false;
        }
    }

    /** @internal Reset schema cache between tests. */
    public static function forgetTableAvailabilityCache(): void
    {
        static::$tableAvailable = null;
    }

    /**
     * Create / heal the table when a deploy skipped migrations.
     * Without this, bell creates fail silently and the dropdown can 500.
     */
    public static function ensureTable(): void
    {
        if (static::tableAvailable()) {
            static::ensureAudienceColumn();

            return;
        }

        try {
            if (Schema::hasTable('in_app_notifications')) {
                static::$tableAvailable = true;
                static::ensureAudienceColumn();

                return;
            }

            if (! Schema::hasTable('users')) {
                return;
            }

            Schema::create('in_app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('audience', 32)->default('all');
                $table->string('type', 64);
                $table->string('category', 32)->default('system');
                $table->string('title');
                $table->text('message')->nullable();
                $table->string('icon', 64)->nullable();
                $table->string('priority', 16)->default('normal');
                $table->string('status', 16)->default('unread');
                $table->string('related_type')->nullable();
                $table->unsignedBigInteger('related_id')->nullable();
                $table->string('action_label')->nullable();
                $table->string('action_url', 1024)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'status', 'created_at']);
                $table->index(['user_id', 'category', 'created_at']);
                $table->index(['related_type', 'related_id']);
                $table->index(['user_id', 'type']);
                $table->index(['user_id', 'audience', 'status', 'created_at'], 'in_app_notifications_user_audience_status_idx');
            });

            static::$tableAvailable = true;

            Log::warning('in_app_notifications table was missing — created at runtime');
        } catch (\Throwable $e) {
            try {
                static::$tableAvailable = Schema::hasTable('in_app_notifications');
            } catch (\Throwable) {
                static::$tableAvailable = false;
            }

            if (static::$tableAvailable) {
                static::ensureAudienceColumn();

                return;
            }

            Log::error('Could not create in_app_notifications at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected static function ensureAudienceColumn(): void
    {
        try {
            if (! Schema::hasTable('in_app_notifications') || Schema::hasColumn('in_app_notifications', 'audience')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        try {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->string('audience', 32)->default('all')->after('user_id');
            });
            Log::warning('in_app_notifications.audience was missing — added at runtime');
        } catch (\Throwable $e) {
            try {
                if (Schema::hasColumn('in_app_notifications', 'audience')) {
                    return;
                }
            } catch (\Throwable) {
                // ignore
            }
            Log::error('Could not add in_app_notifications.audience at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

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
     * Staff roles (admin/marketing) see ops + shared "all" rows only.
     */
    public function scopeForAudience($query, ?string $role)
    {
        $role = $role ? strtolower(trim($role)) : null;
        if (! $role) {
            return $query;
        }

        if (in_array($role, ['admin', 'marketing'], true)) {
            return $query->where(function ($q) {
                $q->where('audience', self::AUDIENCE_ADMIN)
                    ->orWhere('audience', self::AUDIENCE_ALL)
                    ->orWhereNull('audience');
            });
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
            ->notArchivedClock();
    }

    public function scopeUnread($query)
    {
        return $query->where('status', self::STATUS_UNREAD);
    }

    /**
     * Hide order-status rows replaced by a newer live-status bell.
     * Does not delete; meta.superseded_at is the marker.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotSuperseded($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('meta')
                ->orWhereNull('meta->superseded_at');
        });
    }

    /**
     * Leftover Hostinger archived_at is not a real archive. whereNull
     * hid those bells from the inbox and badge.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotArchivedClock($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('archived_at')
                ->orWhere('archived_at', '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere('archived_at', '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereArchivedClockIsRecorded($query)
    {
        return $query->whereNotNull('archived_at')
            ->where('archived_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('archived_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    public function isArchived(): bool
    {
        if ($this->status === self::STATUS_ARCHIVED) {
            return true;
        }

        try {
            $at = $this->archived_at;
        } catch (\Throwable) {
            return false;
        }

        return $at instanceof \DateTimeInterface;
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

    /**
     * Restore a read item to unread. Archived rows stay archived so they
     * do not reappear in the bell Unread list or increment the badge.
     */
    public function markUnread(): self
    {
        if ($this->isArchived()) {
            return $this;
        }

        if ($this->status !== self::STATUS_UNREAD) {
            $this->forceFill([
                'status' => self::STATUS_UNREAD,
                'read_at' => null,
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
            'is_archived' => $this->isArchived(),
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
