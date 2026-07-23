<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkSiteRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_SHEET_SENT = 'sheet_sent';

    public const STATUS_SEEDED = 'seeded';

    public const STATUS_AWAITING_PUBLISHER = 'awaiting_publisher';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'publisher_id',
        'handled_by',
        'status',
        'estimated_count',
        'publisher_note',
        'admin_notes',
        'sheet_sent_at',
        'seeded_at',
        'completed_at',
    ];

    protected $casts = [
        'estimated_count' => 'integer',
        'sheet_sent_at' => 'datetime',
        'seeded_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkSiteRequestItem::class);
    }

    public function awaitingDetailsCount(): int
    {
        return $this->sites()->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)->count();
    }

    public function readyForReviewCount(): int
    {
        return $this->sites()->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW)->count();
    }

    public function refreshProgressStatus(): void
    {
        if (in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REQUESTED, self::STATUS_SHEET_SENT], true)) {
            return;
        }

        $total = $this->sites()->count();
        if ($total === 0) {
            return;
        }

        $awaiting = $this->awaitingDetailsCount();
        if ($awaiting === 0) {
            $this->forceFill([
                'status' => self::STATUS_COMPLETED,
                'completed_at' => $this->completed_at ?? now(),
            ])->save();

            return;
        }

        $this->forceFill([
            'status' => self::STATUS_AWAITING_PUBLISHER,
            'completed_at' => null,
        ])->save();
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    /**
     * Marketer-facing status label for queue clarity.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REQUESTED => 'Waiting on marketer',
            self::STATUS_SHEET_SENT => 'Sheet emailed',
            self::STATUS_SEEDED => 'Drafts seeded',
            self::STATUS_AWAITING_PUBLISHER => 'Waiting on publisher',
            self::STATUS_COMPLETED => 'Completed — ready to verify',
            self::STATUS_CANCELLED => 'Cancelled',
            default => str_replace('_', ' ', (string) $this->status),
        };
    }
}
