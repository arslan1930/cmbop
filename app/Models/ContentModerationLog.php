<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class ContentModerationLog extends Model
{
    use ToleratesMissingSchema, ToleratesUnparseableDates;

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ERROR = 'error';

    public const CATEGORY_CUSTOM = 'custom';

    protected $fillable = [
        'user_id',
        'order_id',
        'order_item_id',
        'content_submission_id',
        'document_url',
        'document_id',
        'status',
        'passed',
        'max_confidence',
        'detected_category',
        'category_scores',
        'quality_report',
        'signals',
        'error_code',
        'error_message',
        'word_count',
        'scan_token',
        'admin_override',
        'overridden_by',
        'overridden_at',
        'admin_notes',
    ];

    /**
     * True when this row records a scan that never actually happened.
     *
     * Moderation being switched off still writes an approved row so checkout can
     * proceed, which means the audit trail reads as a clean pass. Nothing looked
     * at the article, so anywhere this log is shown to a person has to say so.
     */
    public function wasSkipped(): bool
    {
        return (bool) ($this->signals['moderation_disabled'] ?? false);
    }

    protected $casts = [
        'passed' => 'boolean',
        'admin_override' => 'boolean',
        'max_confidence' => 'integer',
        'word_count' => 'integer',
        'category_scores' => 'array',
        'quality_report' => 'array',
        'signals' => 'array',
        'overridden_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ContentSubmission::class, 'content_submission_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('signals->moderation_disabled', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotSkipped(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->whereNull('signals')
                ->orWhereNull('signals->moderation_disabled')
                ->orWhere('signals->moderation_disabled', false);
        });
    }

    /**
     * Rejected/error scans that still need a person — current decision only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsDecision(Builder $query): Builder
    {
        $query->whereIn('status', [self::STATUS_REJECTED, self::STATUS_ERROR])
            ->where('admin_override', false)
            ->notSkipped();

        try {
            if (! Schema::hasTable('content_submissions')) {
                return $query;
            }
        } catch (\Throwable) {
            return $query;
        }

        $logs = $query->getModel()->getTable();

        return $query->where(function (Builder $outer) use ($logs) {
            $outer->whereNull($logs.'.content_submission_id')
                ->orWhereDoesntHave('submission')
                ->orWhereHas('submission', function (Builder $submission) use ($logs) {
                    $submission->where(function (Builder $current) use ($logs) {
                        $current->whereNull('moderation_log_id')
                            ->orWhereColumn('content_submissions.moderation_log_id', $logs.'.id');
                    });
                });
        });
    }

    public function displayTitle(): string
    {
        $submission = $this->relationLoaded('submission') ? $this->submission : null;
        if ($submission) {
            $title = trim((string) ($submission->title ?: $submission->original_filename));
            if ($title !== '') {
                return $title;
            }
        }

        $url = trim((string) $this->document_url);
        if (preg_match('#^https?://#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : $url;
        }

        return $url !== '' ? $url : 'Scan #'.$this->id;
    }

    public function categoryLabel(): string
    {
        $key = (string) ($this->detected_category ?? '');
        if ($key === self::CATEGORY_CUSTOM) {
            return 'Extra prohibited keywords';
        }
        if ($key === '') {
            return '—';
        }

        $label = config('content_moderation.categories.'.$key.'.label');

        return is_string($label) && $label !== '' ? $label : $key;
    }

    public function articleUrl(): ?string
    {
        // Only link when the FK is live. upload:{id} without a row 404s after delete.
        if ($this->content_submission_id) {
            return route('admin.content-library.show', $this->content_submission_id);
        }

        $url = trim((string) $this->document_url);
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return null;
    }

    public function articleUrlIsExternal(): bool
    {
        $url = $this->articleUrl();

        return is_string($url) && preg_match('#^https?://#i', $url) === 1;
    }

    public function resolvedSubmissionId(): ?int
    {
        if ($this->content_submission_id) {
            return (int) $this->content_submission_id;
        }

        if (preg_match('/^upload:(\d+)$/', (string) $this->document_url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function isUsableApproval(int $withinSeconds = 900): bool
    {
        if ($this->wasSkipped()) {
            return false;
        }

        return $this->passed
            && $this->status === self::STATUS_APPROVED
            && $this->created_at
            && $this->created_at->gte(now()->subSeconds($withinSeconds));
    }

    /**
     * Queue override may only change the article's current scan, not an older row.
     * URL-only logs (no linked article) are always their own current decision.
     */
    public function isCurrentDecision(?ContentSubmission $submission = null): bool
    {
        $submission ??= $this->submission;
        if (! $submission instanceof ContentSubmission) {
            return true;
        }

        $currentId = (int) ($submission->moderation_log_id ?? 0);
        if ($currentId === 0) {
            return true;
        }

        return $currentId === (int) $this->id;
    }

    public function isOverridable(?ContentSubmission $submission = null): bool
    {
        if ($this->wasSkipped() || $this->admin_override || $this->passed) {
            return false;
        }

        if (! in_array($this->status, [self::STATUS_REJECTED, self::STATUS_ERROR], true)) {
            return false;
        }

        return $this->isCurrentDecision($submission);
    }
}
