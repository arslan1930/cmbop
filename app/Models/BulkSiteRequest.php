<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkSiteRequest extends Model
{
    /** Publisher submit and marketer Done/seed share this per-request ceiling. */
    public const MAX_SITES_PER_REQUEST = 200;

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
        'cancel_reason',
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

    public function detailsCompleteCount(): int
    {
        return $this->sites()->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)->count();
    }

    public function readyForReviewCount(): int
    {
        return $this->sites()->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW)->count();
    }

    /**
     * Sites still with the publisher (filling details or reviewing before submit).
     */
    public function pendingPublisherCount(): int
    {
        return $this->sites()
            ->whereIn('onboarding_status', [
                Site::ONBOARDING_AWAITING_DETAILS,
                Site::ONBOARDING_DETAILS_COMPLETE,
            ])
            ->count();
    }

    public function refreshProgressStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $total = $this->sites()->count();
        $pendingPublisher = $this->pendingPublisherCount();
        $pendingItems = $this->pendingItemsCount();

        // Stay at the start until staff Dones a row or every submitted row is resolved.
        // Do not bail just because every draft was deleted — leftover URL+price
        // rows must fall through to SEEDED so show-page heal and the marketer
        // queue label stay honest (Completed / Waiting on publisher with 0 sites).
        if (in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_SHEET_SENT], true)) {
            if ($pendingItems > 0 || ($total === 0 && $this->items()->doesntExist())) {
                return;
            }
        }

        // Publisher still filling/reviewing seeded drafts.
        if ($pendingPublisher > 0) {
            $this->forceFill([
                'status' => self::STATUS_AWAITING_PUBLISHER,
                'completed_at' => null,
            ])->save();

            return;
        }

        // Publisher finished current drafts, but marketer still has URL+price rows to Done.
        if ($pendingItems > 0) {
            $this->forceFill([
                'status' => self::STATUS_SEEDED,
                'completed_at' => null,
            ])->save();

            return;
        }

        // Every row is added or rejected, and no publisher work remains.
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $this->completed_at ?? now(),
        ])->save();
    }

    /**
     * Publisher-submitted URL + price rows that still need marketer Done/seed.
     */
    public function pendingItemsCount(): int
    {
        return $this->items()->pending()->count();
    }

    public function rejectedItemsCount(): int
    {
        return $this->items()->rejected()->count();
    }

    public function addedItemsCount(): int
    {
        return $this->items()->whereNotNull('site_id')->count();
    }

    public function hasPendingItems(): bool
    {
        return $this->pendingItemsCount() > 0;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    /**
     * Marketer can still add draft sites (Done form / advanced seed).
     * Stays true when status flipped to completed after a partial seed while
     * URL+price rows remain pending.
     */
    public function canAddDraftSites(): bool
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }

        if ($this->hasPendingItems()) {
            return true;
        }

        // Legacy requests without item rows still use the paste-seed box while open.
        return $this->isOpen();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Sheet emailed is only a start-of-job flag. Never rewind a live batch.
     */
    public function canMarkSheetSent(): bool
    {
        if ($this->status === self::STATUS_REQUESTED) {
            return true;
        }

        return $this->status === self::STATUS_SHEET_SENT
            && $this->sites()->doesntExist();
    }

    /**
     * Marketer-facing status label for queue clarity.
     */
    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }

    public static function statusLabelFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_REQUESTED => 'Waiting on marketer',
            self::STATUS_SHEET_SENT => 'Sheet emailed',
            self::STATUS_SEEDED => 'Drafts seeded',
            self::STATUS_AWAITING_PUBLISHER => 'Waiting on publisher',
            self::STATUS_COMPLETED => 'Completed — ready to verify',
            self::STATUS_CANCELLED => 'Cancelled',
            default => str_replace('_', ' ', (string) $status),
        };
    }
}
