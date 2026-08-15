<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
            ->notArchived()
            ->whereIn('onboarding_status', [
                Site::ONBOARDING_AWAITING_DETAILS,
                Site::ONBOARDING_DETAILS_COMPLETE,
            ])
            ->count();
    }

    /**
     * Status drifted from sites/items (staff verify/unverify, leftover
     * deletes, claim transfer). Show and publisher submit share this so a
     * stale awaiting_publisher row cannot block a new bulk forever, and a
     * stale completed row cannot hide publisher work that is still owed.
     */
    public function needsProgressHeal(): bool
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }

        $hasPendingItems = $this->hasPendingItems();
        $hasSites = $this->sites()->notArchived()->exists();
        $pendingPublisher = $this->pendingPublisherCount();

        // Unverify/deactivate restore onboarding; status may still say completed.
        if ($pendingPublisher > 0 && $this->status !== self::STATUS_AWAITING_PUBLISHER) {
            return true;
        }

        if ($hasPendingItems && ($this->status === self::STATUS_COMPLETED || ! $hasSites)) {
            return true;
        }

        // Reject-all leftover: no pending rows, no live drafts, not a legacy
        // sheet (count set, no item rows). Show must complete it or the
        // marketer list keeps a ghost "Waiting on marketer" batch.
        if (! $hasSites && ! $hasPendingItems
            && in_array($this->status, [
                self::STATUS_REQUESTED,
                self::STATUS_SHEET_SENT,
                self::STATUS_SEEDED,
            ], true)
            && ! ($this->items()->doesntExist() && (int) $this->estimated_count > 0)) {
            return true;
        }

        if (! $hasSites && in_array($this->status, [
            self::STATUS_AWAITING_PUBLISHER,
            self::STATUS_SEEDED,
        ], true)) {
            return true;
        }

        return in_array($this->status, [
            self::STATUS_AWAITING_PUBLISHER,
            self::STATUS_SEEDED,
        ], true) && $pendingPublisher === 0;
    }

    public function healProgressStatusIfStale(): bool
    {
        if (! $this->needsProgressHeal()) {
            return false;
        }

        $this->refreshProgressStatus();

        return true;
    }

    public function refreshProgressStatus(bool $keepLegacySheetOpen = false): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $total = $this->sites()->notArchived()->count();
        $pendingItems = $this->pendingItemsCount();

        // Brand-new requested batches stay put. Sheet-sent with no leftover
        // URL+price rows stays sheet-sent (legacy). Sheet-sent + pending rows
        // and no drafts must become "Waiting on marketer" so heal/index match.
        // Publisher-submitted URL+price rows with no drafts yet stay requested.
        $isLegacySheet = $this->items()->doesntExist() && (int) $this->estimated_count > 0;
        if ($this->status === self::STATUS_REQUESTED && $total === 0 && $pendingItems > 0) {
            return;
        }
        if ($this->status === self::STATUS_SHEET_SENT && $total === 0 && $pendingItems === 0 && $isLegacySheet) {
            return;
        }

        // Last/only draft deleted: the URL+price row is pending again (site_id nullOnDelete).
        if ($total === 0) {
            if ($pendingItems > 0) {
                $this->forceFill([
                    'status' => self::STATUS_REQUESTED,
                    'completed_at' => null,
                ])->save();

                return;
            }

            // Legacy sheet (count set, no item rows): staff delete of the last
            // seed must stay open so they can reseed. Claim transfer of the
            // last listing is finished — do not rewind into a fake sheet.
            if ($isLegacySheet && ($keepLegacySheetOpen
                || in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_SHEET_SENT], true))) {
                $this->forceFill([
                    'status' => self::STATUS_REQUESTED,
                    'completed_at' => null,
                ])->save();

                return;
            }

            $this->forceFill([
                'status' => self::STATUS_COMPLETED,
                'completed_at' => $this->completed_at ?? now(),
            ])->save();

            return;
        }

        $pendingPublisher = $this->pendingPublisherCount();

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

        // Every seeded site left the publisher stage and no pending rows remain.
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
        return $this->items()->whereNull('site_id')->count();
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

        // Legacy sheet workflow: open request, no item rows yet, a count was set.
        // Reject-all deletes items and sets estimated_count to 0 — not legacy.
        return $this->isOpen()
            && $this->sites()->notArchived()->doesntExist()
            && $this->items()->doesntExist()
            && (int) $this->estimated_count > 0;
    }

    public function canCancel(): bool
    {
        return ! $this->isCancelled();
    }

    /**
     * Publisher cannot start a new bulk while this one still has work.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBlockingPublisher($query)
    {
        return $query->where(function ($outer) {
            $outer->where(function ($open) {
                $open->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED])
                    ->where(function ($inner) {
                        $inner->whereHas('items', fn ($items) => $items->whereNull('site_id'))
                            ->orWhereHas('sites', fn ($sites) => $sites->notArchived())
                            // Legacy sheet workflow: count set, no item rows yet.
                            ->orWhere(function ($legacy) {
                                $legacy->where('estimated_count', '>', 0)
                                    ->whereDoesntHave('items')
                                    ->whereDoesntHave('sites', fn ($sites) => $sites->notArchived());
                            });
                    });
            })->orWhere(function ($completedStillOpen) {
                $completedStillOpen->where('status', self::STATUS_COMPLETED)
                    ->where(function ($q) {
                        $q->whereHas('items', fn ($items) => $items->whereNull('site_id'))
                            ->orWhereHas('sites', fn ($sites) => $sites->notArchived()
                                ->whereIn('onboarding_status', [
                                    Site::ONBOARDING_AWAITING_DETAILS,
                                    Site::ONBOARDING_DETAILS_COMPLETE,
                                ]));
                    });
            });
        });
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
        if (! in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_SHEET_SENT], true)) {
            return false;
        }

        return $this->sites()->notArchived()->doesntExist();
    }

    /**
     * Marketer-facing status label for queue clarity.
     */
    public function statusLabel(): string
    {
        if ($this->status === self::STATUS_COMPLETED
            && $this->sites()->doesntExist()
            && ! $this->hasPendingItems()) {
            return 'Finished';
        }

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
