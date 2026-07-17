<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ContentSubmission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_NEEDS_IMPROVEMENT = 'needs_improvement';

    public const STATUS_ERROR = 'error';

    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_SCHEDULED = 'scheduled';

    protected $fillable = [
        'user_id',
        'site_id',
        'copy_index',
        'cart_key',
        'original_filename',
        'title',
        'country',
        'language',
        'disk',
        'path',
        'mime',
        'extension',
        'size_bytes',
        'extracted_text',
        'preview_html',
        'word_count',
        'uniqueness_score',
        'quality_score',
        'evaluation_status',
        'evaluation_report',
        'evaluated_at',
        'approval_notified_at',
        'moderation_status',
        'moderation_log_id',
        'scan_token',
        'anchor_text',
        'target_url',
        'feature_image_url',
        'publication_mode',
        'scheduled_publish_at',
        'timezone',
        'wizard_step',
        'draft_payload',
        'order_id',
        'order_item_id',
        'expires_at',
        'archived_at',
    ];

    protected $casts = [
        'copy_index' => 'integer',
        'size_bytes' => 'integer',
        'word_count' => 'integer',
        'uniqueness_score' => 'integer',
        'quality_score' => 'integer',
        'wizard_step' => 'integer',
        'draft_payload' => 'array',
        'evaluation_report' => 'array',
        'scheduled_publish_at' => 'datetime',
        'evaluated_at' => 'datetime',
        'approval_notified_at' => 'datetime',
        'expires_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function moderationLog(): BelongsTo
    {
        return $this->belongsTo(ContentModerationLog::class, 'moderation_log_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isApproved(): bool
    {
        return $this->moderation_status === self::STATUS_APPROVED;
    }

    public function needsCorrection(): bool
    {
        return in_array($this->moderation_status, [
            self::STATUS_NEEDS_IMPROVEMENT,
            self::STATUS_REJECTED,
            self::STATUS_ERROR,
        ], true);
    }

    public function canBeOrdered(): bool
    {
        $minUniqueness = (int) config('content_upload.evaluation.min_uniqueness', 50);

        return $this->moderation_status === self::STATUS_APPROVED
            && (int) ($this->uniqueness_score ?? 0) >= $minUniqueness
            && $this->path
            && $this->order_id === null
            && ! $this->isArchived()
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && filled($this->country)
            && filled($this->language);
    }

    public function isInUse(): bool
    {
        return $this->order_id !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restoreFromArchive(): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * Primary placement item used for library status + live URL.
     */
    public function placementItem(): ?OrderItem
    {
        if ($this->relationLoaded('orderItem') && $this->orderItem) {
            return $this->orderItem;
        }

        if ($this->relationLoaded('orderItems')) {
            return $this->orderItems->sortBy('id')->first();
        }

        if ($this->order_item_id) {
            return $this->orderItem()->with('site')->first();
        }

        return $this->orderItems()->with('site')->orderBy('id')->first();
    }

    public function liveUrl(): ?string
    {
        $item = $this->placementItem();
        if (! $item || ! $item->hasLiveUrl()) {
            return null;
        }

        return trim((string) $item->live_url) ?: null;
    }

    public function isPublished(): bool
    {
        $item = $this->placementItem();
        if (! $item) {
            return false;
        }

        if ($item->hasLiveUrl()) {
            return true;
        }

        // publisher_status exists in some environments but is not guaranteed by migrations.
        if (Schema::hasColumn('order_items', 'publisher_status')) {
            return in_array((string) $item->publisher_status, ['completed'], true);
        }

        return false;
    }

    /**
     * Library-facing availability for filters and badges.
     *
     * @return 'available'|'in_progress'|'published'|'expired'|'archived'|'needs_fix'|'unavailable'
     */
    public function libraryAvailability(): string
    {
        if ($this->isArchived()) {
            return 'archived';
        }

        if ($this->needsCorrection()) {
            return 'needs_fix';
        }

        if ($this->isPublished()) {
            return 'published';
        }

        if ($this->isInUse()) {
            return 'in_progress';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->canBeOrdered()) {
            return 'available';
        }

        return 'unavailable';
    }

    /**
     * Whether this article's market matches a publisher site.
     */
    public function matchesSite(Site $site): bool
    {
        $country = strtolower(trim((string) $this->country));
        $language = strtolower(trim((string) $this->language));

        if ($country === '' || $language === '') {
            return false;
        }

        return $site->acceptsMarket($country, $language);
    }

    /**
     * Release library ownership so the article can be ordered again
     * (e.g. after Stripe cancel or scheduled-order cancel).
     */
    public function releaseFromOrder(): void
    {
        $this->forceFill([
            'order_id' => null,
            'order_item_id' => null,
        ])->save();
    }

    public function hasLink(): bool
    {
        $anchor = trim((string) $this->anchor_text);
        $target = trim((string) $this->target_url);

        return $anchor !== '' && $target !== '';
    }

    public function isReadyForCheckout(): bool
    {
        if (! $this->canBeOrdered()) {
            return false;
        }

        $anchor = trim((string) $this->anchor_text);
        $target = trim((string) $this->target_url);

        // Advertisers may place orders without a link when the article has none.
        if ($anchor === '' && $target === '') {
            return true;
        }

        return $anchor !== ''
            && $target !== ''
            && (bool) filter_var($target, FILTER_VALIDATE_URL)
            && str_starts_with(strtolower($target), 'https://');
    }

    public function deleteStoredFile(): void
    {
        if ($this->path && Storage::disk($this->disk ?: 'local')->exists($this->path)) {
            Storage::disk($this->disk ?: 'local')->delete($this->path);
        }
    }
}
