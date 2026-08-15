<?php

// app/Models/OrderItem.php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderItem extends Model
{
    /**
     * Advertiser-facing markup multiplier. The extra portion is the platform fee.
     * Example: listing €100 → advertiser pays €115; publisher receives €100.
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
        'homepage_days',
        'homepage_price',
        'social_channels',
        'social_post_urls',
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
        'content_revision_requested',
        'content_revision_requested_at',
        'content_revision_reason',
        'content_revision_resolved_at',
        'auto_approve_triggered',
        'auto_approve_at',
        'auto_approve_reminder_sent_at',
        'accept_nudge_stage',
        'accept_nudge_sent_at',
        'publish_nudge_stage',
        'publish_nudge_sent_at',
        'review_nudge_sent_at',
        'stalled_notice_sent_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'additional_price' => 'decimal:2',
        'homepage_days' => 'integer',
        'homepage_price' => 'decimal:2',
        'social_channels' => 'array',
        'social_post_urls' => 'array',
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
        'content_revision_requested_at' => 'datetime',
        'content_revision_resolved_at' => 'datetime',
        'auto_approve_at' => 'datetime',
        'auto_approve_reminder_sent_at' => 'datetime',
        'auto_approve_triggered' => 'boolean',
        'auto_approve_reminder_sent_at' => 'datetime',
        'accept_nudge_stage' => 'integer',
        'accept_nudge_sent_at' => 'datetime',
        'publish_nudge_stage' => 'integer',
        'publish_nudge_sent_at' => 'datetime',
        'review_nudge_sent_at' => 'datetime',
        'stalled_notice_sent_at' => 'datetime',
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

    public function disputes()
    {
        return $this->hasMany(OrderItemDispute::class);
    }

    public function latestDispute()
    {
        return $this->hasOne(OrderItemDispute::class)->latestOfMany();
    }

    /**
     * Upheld dispute clawback already refunded this line. It must not keep
     * claiming the Content Library article after order_id is released.
     */
    public function isClawedBack(): bool
    {
        if (! OrderItemDispute::tableAvailable()) {
            return false;
        }

        if ($this->relationLoaded('disputes')) {
            return $this->disputes->contains(
                fn (OrderItemDispute $dispute) => $dispute->status === OrderItemDispute::STATUS_UPHELD
            );
        }

        return $this->disputes()
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->exists();
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
     * True when this placement was fulfilled from Content Library.
     * After the article is deleted, nullOnDelete clears content_submission_id
     * but the snapshotted file name/path still mark the line as a library row.
     */
    public function looksLikeLibraryLine(): bool
    {
        return (int) ($this->content_submission_id ?? 0) > 0
            || filled($this->content_path)
            || filled($this->content_original_name)
            || $this->isInternalAdvertiserDownloadUrl(trim((string) ($this->content_link ?: '')));
    }

    /**
     * Uploaded article on the submission or a path snapshotted onto the item.
     */
    public function hasDownloadableContent(): bool
    {
        $submission = $this->relatedContentSubmission();

        if ($submission && $submission->hasStoredFile()) {
            return true;
        }

        return filled($this->content_path);
    }

    /**
     * External article URL only. Library fulfillments store the advertiser
     * download route here, which RoleMiddleware blocks for staff.
     */
    public function publicContentLink(): ?string
    {
        $link = trim((string) ($this->content_link ?: ''));
        if ($link === '') {
            return null;
        }

        if ($this->isInternalAdvertiserDownloadUrl($link)) {
            return null;
        }

        return $link;
    }

    public function briefAnchorText(): ?string
    {
        $anchor = trim((string) ($this->anchor_text ?: ''));
        if ($anchor !== '') {
            return $anchor;
        }

        $fromSubmission = trim((string) ($this->relatedContentSubmission()?->anchor_text ?: ''));

        return $fromSubmission !== '' ? $fromSubmission : null;
    }

    public function briefTargetUrl(): ?string
    {
        $target = trim((string) ($this->target_url ?: ''));
        if ($target !== '') {
            return $target;
        }

        $fromSubmission = trim((string) ($this->relatedContentSubmission()?->target_url ?: ''));

        return $fromSubmission !== '' ? $fromSubmission : null;
    }

    protected function relatedContentSubmission(): ?ContentSubmission
    {
        return $this->relationLoaded('contentSubmission')
            ? $this->contentSubmission
            : $this->contentSubmission()->first();
    }

    protected function isInternalAdvertiserDownloadUrl(string $link): bool
    {
        $path = parse_url($link, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $link;
        }

        return (bool) preg_match('#/content-submissions/\d+/download/?$#', $path);
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
        return $this->markedUpBasePrice();
    }

    /**
     * Marked-up base paid by the advertiser (excludes sensitive / homepage add-ons).
     */
    public function markedUpBasePrice(): float
    {
        return round(
            (float) $this->price
            - (float) ($this->additional_price ?? 0)
            - (float) ($this->homepage_price ?? 0),
            2
        );
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
            $this->publisherBasePrice()
            + (float) ($this->additional_price ?? 0)
            + (float) ($this->homepage_price ?? 0),
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
     * Must match publisherPayoutAmount(): snapshotted publisher_price + add-ons,
     * not advertiser price / 1.15 (discounts and tiered fees break that).
     *
     * @param  string  $table  Qualify columns when the query joins other tables.
     */
    public static function publisherPayoutSqlExpression(string $table = 'order_items')
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

        $qualified = $table === '' ? '' : rtrim($table, '.').'.';
        $base = "({$qualified}price - COALESCE({$qualified}additional_price, 0) - COALESCE({$qualified}homepage_price, 0))";
        $extras = "COALESCE({$qualified}additional_price, 0) + COALESCE({$qualified}homepage_price, 0)";
        $legacyPayout = "{$base} / {$rate}";

        $publisherBase = $legacyPayout;
        try {
            if (Schema::hasColumn('order_items', 'publisher_price')) {
                $publisherBase = "COALESCE({$qualified}publisher_price, {$legacyPayout})";
            }
        } catch (\Throwable) {
            // tests / partially migrated schemas
        }

        return DB::raw("ROUND({$publisherBase} + {$extras}, 2)");
    }

    /**
     * SQL expression for platform fee retained on the base price.
     */
    public static function platformFeeSqlExpression()
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
            'COALESCE(platform_fee_amount, (price - COALESCE(additional_price, 0) - COALESCE(homepage_price, 0)) - COALESCE(publisher_price, (price - COALESCE(additional_price, 0) - COALESCE(homepage_price, 0)) / '.$rate.'))'
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
     * Whether this placement includes homepage placement days.
     */
    public function hasHomepagePlacement(): bool
    {
        return $this->homepage_days !== null && (int) $this->homepage_days > 0;
    }

    /**
     * Snapshotted social channels the publisher offered on this order (always €0).
     *
     * @return list<string>
     */
    public function enabledSocialChannels(): array
    {
        $raw = $this->social_channels;
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $allowed = config('site_placement.social_channels', ['facebook', 'instagram', 'x']);
        $normalized = array_map(
            static fn ($c) => strtolower(trim((string) $c)),
            $raw
        );
        $out = [];
        foreach ($allowed as $channel) {
            if (in_array($channel, $normalized, true)) {
                $out[] = $channel;
            }
        }

        return $out;
    }

    public function offersSocialPromotion(): bool
    {
        return $this->enabledSocialChannels() !== [];
    }

    /**
     * @return array<string, string>
     */
    public function socialPostUrls(): array
    {
        $raw = $this->social_post_urls;
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];
        foreach ($this->enabledSocialChannels() as $channel) {
            $url = $raw[$channel] ?? null;
            if (is_string($url) && trim($url) !== '') {
                $out[$channel] = trim($url);
            }
        }

        return $out;
    }

    public function hasSocialPostUrls(): bool
    {
        return $this->socialPostUrls() !== [];
    }

    /**
     * Short advertiser-facing extras for acceptance email / bell (pre-publish).
     *
     * @return list<string>
     */
    public function purchasedPlacementSummaries(): array
    {
        $parts = [];
        if ($this->hasHomepagePlacement()) {
            $days = (int) $this->homepage_days;
            $fee = (float) ($this->homepage_price ?? 0);
            $parts[] = 'homepage ('.$days.' day'.($days === 1 ? '' : 's')
                .($fee > 0 ? ', +€'.number_format($fee, 2) : ', free').')';
        }
        if ($this->offersSocialPromotion()) {
            $labels = collect($this->enabledSocialChannels())
                ->map(fn (string $c) => $this->socialChannelLabel($c))
                ->all();
            $parts[] = 'social ('.implode(', ', $labels).')';
        }

        return $parts;
    }

    public function purchasedPlacementSentence(): ?string
    {
        $parts = $this->purchasedPlacementSummaries();
        if ($parts === []) {
            return null;
        }

        return 'Includes '.implode(' and ', $parts).'.';
    }

    public function socialChannelLabel(string $channel): string
    {
        return match (strtolower($channel)) {
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'x' => 'X',
            default => ucfirst($channel),
        };
    }

    /**
     * Helper method to get formatted price breakdown
     */
    public function getPriceBreakdownAttribute()
    {
        $homepagePrice = (float) ($this->homepage_price ?? 0);

        return [
            'base_price' => $this->markedUpBasePrice(),
            'additional_price' => (float) ($this->additional_price ?? 0),
            'sensitive_type' => $this->hasSensitivePricing() ? $this->sensitive_type : null,
            'homepage_days' => $this->hasHomepagePlacement() ? (int) $this->homepage_days : null,
            'homepage_price' => $homepagePrice,
            'total_price' => (float) $this->price,
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
     * Publisher already received earnings for this line (approve or auto-approve).
     * Used so a later Approve cannot credit the same placement twice.
     */
    public function isPayoutComplete(): bool
    {
        if ((bool) $this->auto_approve_triggered) {
            return true;
        }

        $table = $this->getTable();
        if (Schema::hasColumn($table, 'completed_at') && $this->completed_at) {
            return true;
        }

        if (Schema::hasColumn($table, 'publisher_status') && $this->publisher_status === 'completed') {
            return true;
        }

        return $this->hasPublisherEarningsLedger();
    }

    /**
     * Unpaid line the advertiser may complete: live URL in, no open revision.
     */
    public function isReadyForAdvertiserApprove(): bool
    {
        if (! $this->hasLiveUrl()) {
            return false;
        }

        if ($this->isContentRevisionRequested() || $this->isModificationRequested()) {
            return false;
        }

        return true;
    }

    public function hasPublisherEarningsLedger(): bool
    {
        if (! $this->id || ! Schema::hasTable('wallet_transactions')) {
            return false;
        }

        try {
            return WalletTransaction::query()
                ->where('reference', 'ORDER-ITEM-'.$this->id)
                ->where('type', WalletTransaction::TYPE_TRANSFER_IN)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Staff can chase from the order show page: paid, still open, no live URL yet.
     * Same endpoint as the stalled-order queue; does not require the cadence to be exhausted.
     */
    public function canAdminRemindPublisher(?Order $order = null): bool
    {
        $order ??= $this->relationLoaded('order') ? $this->order : $this->order()->first();
        if (! $order || $order->payment_status !== 'paid') {
            return false;
        }
        if (! in_array($order->status, ['pending', 'processing', 'review'], true)) {
            return false;
        }
        if ($order->isAwaitingScheduledRelease()) {
            return false;
        }
        if ($this->hasLiveUrl()) {
            return false;
        }

        $publisher = $this->site?->publisher;

        return filled($publisher?->email);
    }

    /**
     * Track the stalled-order reminder uses: accept until accepted, then publish.
     */
    public function adminRemindTrack(): string
    {
        return $this->accepted_at === null ? 'accept' : 'publish';
    }

    /**
     * Check if modification was requested
     */
    public function isModificationRequested()
    {
        return $this->modification_requested === 'yes';
    }

    /**
     * Publisher asked the advertiser to revise / resend the article.
     */
    public function isContentRevisionRequested(): bool
    {
        return ($this->content_revision_requested ?? 'no') === 'yes';
    }

    /**
     * Whether any line on the order still needs a revised article from the advertiser.
     */
    public static function orderHasOpenContentRevision(int $orderId, ?int $exceptItemId = null): bool
    {
        if (! Schema::hasColumn((new static)->getTable(), 'content_revision_requested')) {
            return false;
        }

        $query = static::query()
            ->where('order_id', $orderId)
            ->where('content_revision_requested', 'yes');

        if ($exceptItemId) {
            $query->where('id', '!=', $exceptItemId);
        }

        return $query->exists();
    }

    /**
     * Restart the advertiser review / auto-approve window for every live URL on the order.
     * Used when an order finally enters review after being held for a content revision.
     */
    public static function restartAutoApproveClocksForOrder(int $orderId): void
    {
        $payload = [
            'live_url_submitted_at' => now(),
            'auto_approve_triggered' => false,
        ];

        if (Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
            $payload['auto_approve_reminder_sent_at'] = null;
        }

        static::query()
            ->where('order_id', $orderId)
            ->whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->get()
            ->each(function (self $item) use ($payload) {
                if ($item->isPayoutComplete()) {
                    return;
                }

                $item->update($payload);
            });
    }

    /**
     * Check if auto-approve has been triggered
     */
    public function isAutoApproved()
    {
        return (bool) $this->auto_approve_triggered;
    }

    /**
     * Configured auto-approve window in hours (default 72 = 3 days).
     */
    public static function autoApproveHours(): int
    {
        return max(1, (int) config('orders.auto_approve_hours', 72));
    }

    /**
     * Hours before auto-approve when the reminder should fire.
     */
    public static function autoApproveReminderHoursBefore(): int
    {
        return max(0, (int) config('orders.auto_approve_reminder_hours_before', 24));
    }

    /**
     * Whether a failed live URL health check blocks auto-approve.
     */
    public static function autoApproveRequiresLiveUrlOk(): bool
    {
        return (bool) config('orders.auto_approve_require_live_url_ok', true);
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

        // Must not be waiting on a publisher-requested content revision
        if ($this->isContentRevisionRequested()) {
            return false;
        }

        // Must not already be auto-approved or otherwise paid out
        if ($this->isAutoApproved() || $this->isPayoutComplete()) {
            return false;
        }

        // Must have submission timestamp
        if (! $this->live_url_submitted_at) {
            return false;
        }

        // Optional hard gate: failed health check blocks auto-complete
        if (self::autoApproveRequiresLiveUrlOk() && $this->live_url_check_ok === false) {
            return false;
        }

        $hoursPassed = $this->live_url_submitted_at->diffInHours(Carbon::now(), true);

        return $hoursPassed >= self::autoApproveHours();
    }

    /**
     * Get hours remaining for auto-approve
     */
    public function getAutoApproveHoursRemaining()
    {
        if (! $this->live_url_submitted_at
            || $this->isModificationRequested()
            || $this->isContentRevisionRequested()
            || $this->isAutoApproved()) {
            return 0;
        }

        $hoursPassed = $this->live_url_submitted_at->diffInHours(Carbon::now(), true);
        $remaining = self::autoApproveHours() - $hoursPassed;

        return $remaining > 0 ? (int) ceil($remaining) : 0;
    }

    /**
     * Whether this item is in the reminder window (~24h left) and not yet reminded.
     */
    public function isReadyForAutoApproveReminder(): bool
    {
        $reminderBefore = self::autoApproveReminderHoursBefore();
        if ($reminderBefore <= 0) {
            return false;
        }

        if (! $this->hasLiveUrl() || ! $this->live_url_submitted_at) {
            return false;
        }

        if ($this->isModificationRequested()
            || $this->isContentRevisionRequested()
            || $this->isAutoApproved()
            || $this->isPayoutComplete()) {
            return false;
        }

        if ($this->auto_approve_reminder_sent_at) {
            return false;
        }

        if (self::autoApproveRequiresLiveUrlOk() && $this->live_url_check_ok === false) {
            return false;
        }

        $remaining = $this->getAutoApproveHoursRemaining();

        return $remaining > 0 && $remaining <= $reminderBefore;
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
