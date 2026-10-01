<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkSiteRequest extends Model
{
    use ToleratesUnparseableDates;

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
        if (! Site::hasSitesColumn('onboarding_status')) {
            return 0;
        }

        return $this->sites()->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)->count();
    }

    public function detailsCompleteCount(): int
    {
        if (! Site::hasSitesColumn('onboarding_status')) {
            return 0;
        }

        return $this->sites()->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)->count();
    }

    public function readyForReviewCount(): int
    {
        if (! Site::hasSitesColumn('onboarding_status')) {
            return 0;
        }

        return $this->sites()->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW)->count();
    }

    /**
     * Sites still with the publisher (filling details, reviewing, or Accept).
     */
    public function pendingPublisherCount(): int
    {
        $query = $this->sites()->notArchived();
        static::constrainSitesPendingPublisher($query);

        return $query->count();
    }

    /**
     * Publisher still owes work on these listings.
     *
     * @param  Builder<Site>  $query
     */
    public static function constrainSitesPendingPublisher($query): void
    {
        $query->where(function ($inner) {
            $added = false;
            if (Site::hasSitesColumn('onboarding_status')) {
                $inner->whereIn('onboarding_status', [
                    Site::ONBOARDING_AWAITING_DETAILS,
                    Site::ONBOARDING_DETAILS_COMPLETE,
                ]);
                $added = true;
            }
            if (Site::hasSitesColumn('publisher_accepted_at')
                && Site::hasSitesColumn('assigned_by_user_id')) {
                if ($added) {
                    $inner->orWhere(fn ($accept) => $accept->pendingPublisherAcceptance());
                } else {
                    $inner->pendingPublisherAcceptance();
                }

                return;
            }
            if (! $added) {
                $inner->whereRaw('0 = 1');
            }
        });
    }

    /**
     * Staff Add site / Add sites in bulk: listings already filled, waiting on Accept.
     */
    public function isStaffAssignedBatch(): bool
    {
        if (! Site::hasSitesColumn('assigned_by_user_id')) {
            return false;
        }

        if (array_key_exists('staff_assigned_count', $this->getAttributes())) {
            return (int) $this->staff_assigned_count > 0;
        }

        return $this->sites()->whereNotNull('assigned_by_user_id')->exists();
    }

    /**
     * @param  list<Site>  $sites
     */
    public static function openForStaffInvites(int $publisherId, int $staffUserId, array $sites): self
    {
        $sites = array_values(array_filter(
            $sites,
            static fn ($site) => $site instanceof Site && (int) $site->id > 0
        ));
        if ($sites === []) {
            throw new \InvalidArgumentException('Staff bulk batch requires at least one saved site.');
        }

        $bulk = static::create([
            'publisher_id' => $publisherId,
            'handled_by' => $staffUserId,
            'status' => self::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => count($sites),
            'seeded_at' => now(),
        ]);

        foreach ($sites as $site) {
            $attrs = [];
            if (Site::hasSitesColumn('bulk_site_request_id')) {
                $attrs['bulk_site_request_id'] = $bulk->id;
            }
            if (Site::hasSitesColumn('added_from_bulk_request')) {
                $attrs['added_from_bulk_request'] = true;
            }
            if ($attrs !== []) {
                $site->forceFill($attrs)->save();
            }

            BulkSiteRequestItem::create([
                'bulk_site_request_id' => $bulk->id,
                'site_url' => (string) $site->site_url,
                'domain' => (string) $site->domain,
                'price' => $site->price,
                'site_id' => $site->id,
            ]);
        }

        $bulk->refreshProgressStatus();

        return $bulk->fresh() ?? $bulk;
    }

    /**
     * Staff-invite site removed: do not re-pend a URL+price row for marketer Done.
     */
    public function forgetUnlinkedStaffInviteItems(): void
    {
        $this->items()->whereNull('site_id')->delete();
    }

    /**
     * Status drifted from sites/items (staff verify/unverify, leftover
     * deletes). Show uses this so a stale awaiting_publisher row cannot
     * block a new bulk forever, and a stale completed row cannot hide
     * publisher work that is still owed.
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

    public function refreshProgressStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $total = $this->sites()->notArchived()->count();
        $pendingItems = $this->pendingItemsCount();

        // Brand-new requested batches stay put. Sheet-sent with no leftover
        // URL+price rows stays sheet-sent (legacy). Sheet-sent + pending rows
        // and no drafts must become "Waiting on marketer" so heal/index match.
        if ($this->status === self::STATUS_REQUESTED && $total === 0) {
            return;
        }
        if ($this->status === self::STATUS_SHEET_SENT && $total === 0 && $pendingItems === 0) {
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

            // Staff-assigned invites were removed; nothing for a marketer to Done.
            if ($this->items()->doesntExist()
                && (int) $this->estimated_count > 0
                && $this->status === self::STATUS_AWAITING_PUBLISHER
                && filled($this->handled_by)
                && $this->seeded_at) {
                $this->forceFill([
                    'status' => self::STATUS_COMPLETED,
                    'completed_at' => $this->completed_at ?? now(),
                ])->save();

                return;
            }

            // Legacy sheet: count set, no item rows — keep the batch open so staff can re-seed.
            if ($this->items()->doesntExist() && (int) $this->estimated_count > 0) {
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
                            ->orWhereHas('sites', function ($sites) {
                                $sites->notArchived();
                                static::constrainSitesPendingPublisher($sites);
                            });
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
     * This batch still stops the publisher from submitting another bulk.
     */
    public function blocksPublisherNewBulk(): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        $pendingItems = array_key_exists('pending_items_count', $this->getAttributes())
            ? (int) $this->pending_items_count
            : $this->pendingItemsCount();
        $sitesCount = array_key_exists('sites_count', $this->getAttributes())
            ? (int) $this->sites_count
            : $this->sites()->notArchived()->count();

        if ($this->status !== self::STATUS_COMPLETED) {
            if ($pendingItems > 0 || $sitesCount > 0) {
                return true;
            }

            return (int) $this->estimated_count > 0
                && $this->items()->doesntExist()
                && $sitesCount === 0;
        }

        $pendingPublisher = array_key_exists('awaiting_details_count', $this->getAttributes())
            || array_key_exists('reviewing_count', $this->getAttributes())
            || array_key_exists('pending_accept_count', $this->getAttributes())
            ? (int) ($this->awaiting_details_count ?? 0)
                + (int) ($this->reviewing_count ?? 0)
                + (int) ($this->pending_accept_count ?? 0)
            : $this->pendingPublisherCount();

        return $pendingItems > 0 || $pendingPublisher > 0;
    }

    /**
     * Marketer-facing status label for queue clarity.
     */
    public function statusLabel(): string
    {
        if ($this->status === self::STATUS_COMPLETED) {
            $pendingItems = array_key_exists('pending_items_count', $this->getAttributes())
                ? (int) $this->pending_items_count
                : $this->pendingItemsCount();
            if ($pendingItems > 0) {
                return self::statusLabelFor(self::STATUS_REQUESTED);
            }
            $ready = array_key_exists('ready_count', $this->getAttributes())
                ? (int) $this->ready_count
                : $this->readyForReviewCount();
            if ($ready > 0) {
                return 'Verify on Sites';
            }

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
            self::STATUS_COMPLETED => 'Finished',
            self::STATUS_CANCELLED => 'Cancelled',
            default => str_replace('_', ' ', (string) $status),
        };
    }
}
