<?php

namespace App\Models;

use App\Services\InAppNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class AgencySiteImport extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_CLOSED = 'closed';

    public const MAX_ROWS = 200;

    protected $fillable = [
        'publisher_id',
        'status',
        'original_filename',
        'dry_run',
        'processed_count',
        'created_count',
        'failed_count',
        'would_create_count',
        'admin_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'processed_count' => 'integer',
        'created_count' => 'integer',
        'failed_count' => 'integer',
        'would_create_count' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'agency_site_import_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(AgencySiteImportFailure::class);
    }

    public function isOpenForReview(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_PARTIAL,
        ], true);
    }

    public function pendingReviewSitesCount(): int
    {
        // Still open if not yet verified (and not soft-rejected via status_reason),
        // or verified but not live. Bulk reject deletes pending rows; Sites Management
        // unverify may leave an unverified row with a status_reason instead.
        return $this->sites()
            ->where(function ($q) {
                $q->where(function ($open) {
                    $open->where(function ($v) {
                        $v->where('verified', false)->orWhereNull('verified');
                    })->where(function ($reason) {
                        $reason->whereNull('status_reason')
                            ->orWhere('status_reason', '');
                    });
                })->orWhere(function ($verifiedInactive) {
                    $verifiedInactive->where('verified', true)
                        ->where(function ($a) {
                            $a->where('active', false)->orWhereNull('active');
                        });
                });
            })
            ->count();
    }

    public function refreshReviewStatus(?User $reviewer = null): void
    {
        if (in_array($this->status, [self::STATUS_CLOSED, self::STATUS_PROCESSING], true)) {
            return;
        }

        if ($this->dry_run) {
            return;
        }

        // Failed-with-sites (legacy stuck batches) can still be closed via Sites Management.
        if ($this->status === self::STATUS_FAILED && (int) $this->created_count <= 0) {
            return;
        }

        $pending = $this->pendingReviewSitesCount();
        if ($pending === 0 && $this->created_count > 0) {
            $becameReviewed = $this->status !== self::STATUS_REVIEWED;
            $payload = [
                'status' => self::STATUS_REVIEWED,
                'reviewed_at' => $this->reviewed_at ?? now(),
            ];
            if ($reviewer && ! $this->reviewed_by) {
                $payload['reviewed_by'] = $reviewer->id;
            }
            $this->forceFill($payload)->save();

            if ($becameReviewed) {
                try {
                    app(InAppNotificationService::class)
                        ->completeAgencySiteImportNotifications($this);
                } catch (\Throwable $e) {
                    Log::warning(
                        'Could not archive agency CSV import notifications: '.$e->getMessage(),
                        ['import_id' => $this->id]
                    );
                }
            }

            return;
        }

        // Re-open if staff deactivated / unverified after review.
        if ($pending > 0 && $this->status === self::STATUS_REVIEWED) {
            $this->forceFill([
                'status' => ((int) $this->failed_count > 0)
                    ? self::STATUS_PARTIAL
                    : self::STATUS_SUBMITTED,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ])->save();

            try {
                app(InAppNotificationService::class)
                    ->notifyAdminsAgencySiteImportSubmitted($this);
            } catch (\Throwable $e) {
                Log::warning(
                    'Could not re-bell admins about reopened agency CSV import: '.$e->getMessage(),
                    ['import_id' => $this->id]
                );
            }
        }
    }

    public function finalizeStatus(): void
    {
        if ($this->dry_run) {
            // Dry runs are audit-only; never enter the admin review queue.
            $this->forceFill(['status' => self::STATUS_CLOSED])->save();

            return;
        }

        if ($this->created_count <= 0) {
            $status = self::STATUS_FAILED;
        } elseif ($this->failed_count > 0) {
            $status = self::STATUS_PARTIAL;
        } else {
            $status = self::STATUS_SUBMITTED;
        }

        $this->forceFill(['status' => $status])->save();
    }
}
