<?php

// app/Models/OrderItem.php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class OrderItem extends Model
{
    /**
     * @deprecated Legacy flat markup. Prefer snapshotted publisher_price / PlatformFeeService.
     */
    public const PLATFORM_MARKUP_RATE = 1.15;

    protected $fillable = [
        'order_id',
        'site_id',
        'site_name',
        'site_url',
        'price',
        'content_link',
        'content_submission_id',
        'content_disk',
        'content_path',
        'content_original_name',
        'content_mime',
        'anchor_text',
        'target_url',
        'feature_image_url',
        'moderation_status',
        'live_url',
        'live_url_submitted_at',
        'live_url_http_status',
        'live_url_check_ok',
        'live_url_checked_at',
        'sensitive_type',
        'additional_price',
        'publisher_price',
        'platform_fee_percent',
        'platform_fee_amount',
        'publisher_status',
        'accepted_at',
        'rejected_at',
        'completed_at',
        'rejection_reason',
        'completion_notes',
        // New modification tracking fields
        'modification_requested',
        'modification_requested_at',
        'auto_approve_triggered',
        'auto_approve_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'additional_price' => 'decimal:2',
        'publisher_price' => 'decimal:2',
        'platform_fee_percent' => 'decimal:2',
        'platform_fee_amount' => 'decimal:2',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'completed_at' => 'datetime',
        'live_url_submitted_at' => 'datetime',
        'live_url_checked_at' => 'datetime',
        'live_url_check_ok' => 'boolean',
        'modification_requested_at' => 'datetime',
        'auto_approve_at' => 'datetime',
        'auto_approve_triggered' => 'boolean',
    ];

    /**
     * Apply a live URL health-check result onto this item (not saved).
     *
     * @param  array{ok: bool, status: ?int, checked_at: \Illuminate\Support\Carbon}  $result
     */
    public function applyLiveUrlHealthCheck(array $result): void
    {
        $this->live_url_check_ok = (bool) ($result['ok'] ?? false);
        $this->live_url_http_status = $result['status'] ?? null;
        $this->live_url_checked_at = $result['checked_at'] ?? now();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function contentSubmission(): BelongsTo
    {
        return $this->belongsTo(ContentSubmission::class);
    }

    /**
     * Get the publisher (site owner) for this order item
     */
    public function getPublisherAttribute()
    {
        if ($this->site) {
            return User::find($this->site->publisher_id);
        }

        return null;
    }

    /**
     * Get the publisher ID for this order item
     */
    public function getPublisherIdAttribute()
    {
        return $this->site?->publisher_id;
    }

    /**
     * Get the publisher name for this order item
     */
    public function getPublisherNameAttribute()
    {
        $publisher = $this->publisher;

        return $publisher ? $publisher->name : 'Unknown Publisher';
    }

    /**
     * Get the publisher email for this order item
     */
    public function getPublisherEmailAttribute()
    {
        $publisher = $this->publisher;

        return $publisher ? $publisher->email : null;
    }

    /**
     * Helper method to get base price (price - additional_price).
     * For advertisers this is the marked-up base (includes platform fee).
     */
    public function getBasePriceAttribute()
    {
        return $this->price - $this->additional_price;
    }

    /**
     * Marked-up base paid by the advertiser (excludes sensitive add-ons).
     */
    public function markedUpBasePrice(): float
    {
        return round((float) $this->price - (float) ($this->additional_price ?? 0), 2);
    }

    /**
     * Publisher listing/base price (snapshotted at checkout when available).
     */
    public function publisherBasePrice(): float
    {
        if ($this->publisher_price !== null && $this->publisher_price !== '') {
            return round((float) $this->publisher_price, 2);
        }

        $rate = self::PLATFORM_MARKUP_RATE;
        try {
            if (function_exists('app') && app()->bound('config')) {
                $configured = config('pricing.legacy_markup_rate');
                if ($configured) {
                    $rate = (float) $configured;
                }
            }
        } catch (\Throwable) {
            // Pure unit tests without a bootstrapped container.
        }

        return round($this->markedUpBasePrice() / $rate, 2);
    }

    /**
     * Amount credited to the publisher on approval.
     * Publisher gets their entered base + sensitive add-ons; platform keeps the hidden fee.
     */
    public function publisherPayoutAmount(): float
    {
        return round(
            $this->publisherBasePrice() + (float) ($this->additional_price ?? 0),
            2
        );
    }

    /**
     * Platform fee retained from the marked-up base price.
     */
    public function platformFeeAmount(): float
    {
        if ($this->platform_fee_amount !== null && $this->platform_fee_amount !== '') {
            return round((float) $this->platform_fee_amount, 2);
        }

        return round($this->markedUpBasePrice() - $this->publisherBasePrice(), 2);
    }

    /**
     * SQL expression for publisher payout amounts (for SUM/aggregates).
     * Prefers snapshotted publisher_price; falls back to legacy flat markup reversal.
     */
    public static function publisherPayoutSqlExpression()
    {
        $rate = self::PLATFORM_MARKUP_RATE;
        try {
            if (function_exists('app') && app()->bound('config')) {
                $configured = config('pricing.legacy_markup_rate');
                if ($configured) {
                    $rate = (float) $configured;
                }
            }
        } catch (\Throwable) {
            // keep legacy constant
        }

        return DB::raw(
            'COALESCE(publisher_price, (price - COALESCE(additional_price, 0)) / '.$rate.') + COALESCE(additional_price, 0)'
        );
    }

    /**
     * Helper method to check if item has sensitive pricing
     */
    public function hasSensitivePricing()
    {
        return ! is_null($this->sensitive_type) && $this->additional_price > 0;
    }

    /**
     * Helper method to get formatted price breakdown
     */
    public function getPriceBreakdownAttribute()
    {
        if ($this->hasSensitivePricing()) {
            return [
                'base_price' => $this->base_price,
                'additional_price' => $this->additional_price,
                'sensitive_type' => $this->sensitive_type,
                'total_price' => $this->price,
            ];
        }

        return [
            'base_price' => $this->price,
            'additional_price' => 0,
            'sensitive_type' => null,
            'total_price' => $this->price,
        ];
    }

    /**
     * Check if live URL has been submitted
     */
    public function hasLiveUrl()
    {
        return ! is_null($this->live_url) && $this->live_url !== '';
    }

    /**
     * Check if modification was requested
     */
    public function isModificationRequested()
    {
        return $this->modification_requested === 'yes';
    }

    /**
     * Check if auto-approve has been triggered
     */
    public function isAutoApproved()
    {
        return (bool) $this->auto_approve_triggered;
    }

    /**
     * Check if order is ready for auto-approve
     */
    public function isReadyForAutoApprove()
    {
        // Must have live URL submitted
        if (! $this->hasLiveUrl()) {
            return false;
        }

        // Must not have modification requested
        if ($this->isModificationRequested()) {
            return false;
        }

        // Must not already be auto-approved
        if ($this->isAutoApproved()) {
            return false;
        }

        // Must have submission timestamp
        if (! $this->live_url_submitted_at) {
            return false;
        }

        // Must have 48 hours passed (absolute: Carbon 3 returns signed diffs by default)
        $hoursPassed = $this->live_url_submitted_at->diffInHours(Carbon::now(), true);

        return $hoursPassed >= 48;
    }

    /**
     * Get hours remaining for auto-approve
     */
    public function getAutoApproveHoursRemaining()
    {
        if (! $this->live_url_submitted_at || $this->isModificationRequested() || $this->isAutoApproved()) {
            return 0;
        }

        $hoursPassed = $this->live_url_submitted_at->diffInHours(Carbon::now(), true);
        $remaining = 48 - $hoursPassed;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Get auto-approve status text
     */
    public function getAutoApproveStatusAttribute()
    {
        if ($this->isAutoApproved()) {
            return 'Approved';
        }

        if ($this->isModificationRequested()) {
            return 'Paused - Modification Requested';
        }

        if (! $this->live_url_submitted_at) {
            return 'Not Started';
        }

        $hoursRemaining = $this->getAutoApproveHoursRemaining();

        if ($hoursRemaining <= 0) {
            return 'Ready for Approval';
        }

        $days = floor($hoursRemaining / 24);
        $hours = $hoursRemaining % 24;

        if ($days > 0) {
            return "Auto-approve in {$days}d {$hours}h";
        }

        return "Auto-approve in {$hoursRemaining}h";
    }

    /**
     * Mark order item as auto-approved
     */
    public function markAsAutoApproved()
    {
        $this->auto_approve_triggered = true;
        $this->auto_approve_at = Carbon::now();
        $this->save();

        return $this;
    }

    /**
     * Request modification (stops auto-approve)
     */
    public function requestModification($reason = null)
    {
        $this->modification_requested = 'yes';
        $this->modification_requested_at = Carbon::now();
        $this->auto_approve_triggered = false;
        $this->completion_notes = $reason ?? 'Modification requested by advertiser';
        $this->save();

        return $this;
    }

    /**
     * Resubmit live URL after modification (resets timer)
     */
    public function resubmitLiveUrl($url)
    {
        $this->live_url = $url;
        $this->live_url_submitted_at = Carbon::now();  // RESET timer
        $this->modification_requested = 'no';
        $this->modification_requested_at = null;
        $this->auto_approve_triggered = false;
        $this->save();

        return $this;
    }

    /**
     * Get status badge class for display
     */
    public function getStatusBadgeClassAttribute()
    {
        switch ($this->publisher_status) {
            case 'pending':
                return 'bg-warning text-dark';
            case 'accepted':
                return 'bg-info text-white';
            case 'rejected':
                return 'bg-danger text-white';
            case 'completed':
                return 'bg-success text-white';
            default:
                return 'bg-secondary text-white';
        }
    }

    /**
     * Get order status (from parent order)
     */
    public function getOrderStatusAttribute()
    {
        return $this->order?->status ?? 'pending';
    }

    /**
     * Get formatted status for display
     */
    public function getFormattedStatusAttribute()
    {
        $orderStatus = $this->order_status;

        if ($orderStatus === 'review' && $this->isModificationRequested()) {
            return 'Modification Requested';
        }

        $statuses = [
            'pending' => 'Pending',
            'processing' => 'In Progress',
            'review' => 'Under Review',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];

        return $statuses[$orderStatus] ?? ucfirst($orderStatus);
    }

    /**
     * Get status badge class from order status
     */
    public function getFormattedStatusBadgeClassAttribute()
    {
        $orderStatus = $this->order_status;

        if ($orderStatus === 'review' && $this->isModificationRequested()) {
            return 'bg-warning text-dark';
        }

        $classes = [
            'pending' => 'status-pending',
            'processing' => 'status-processing',
            'review' => 'status-review',
            'completed' => 'status-completed',
            'cancelled' => 'status-cancelled',
        ];

        return $classes[$orderStatus] ?? 'status-pending';
    }

    /**
     * Get status text for display
     */
    public function getStatusTextAttribute()
    {
        switch ($this->publisher_status) {
            case 'pending':
                return 'Pending';
            case 'accepted':
                return 'Accepted';
            case 'rejected':
                return 'Rejected';
            case 'completed':
                return 'Completed';
            default:
                return ucfirst($this->publisher_status);
        }
    }
}
