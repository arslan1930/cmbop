<?php

namespace App\Services\Orders;

use App\Mail\ContentRevisionFulfilled;
use App\Mail\ContentRevisionRequested;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\OrderChatContactGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ContentRevisionService
{
    public function __construct(
        private InAppNotificationService $notifications,
        private OrderChatContactGuard $chatGuard,
    ) {}

    /**
     * Publisher asks the advertiser to revise / resend the article.
     * One open request at a time; calling again updates the reason.
     *
     * @return array{item: OrderItem, order: Order, site: Site, updated: bool}
     */
    public function requestFromPublisher(OrderItem $item, User $publisher, string $reason): array
    {
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'Please explain what needs to change (at least 10 characters).',
            ]);
        }

        return DB::transaction(function () use ($item, $publisher, $reason) {
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $site = Site::query()
                ->whereKey($locked->site_id)
                ->where('publisher_id', $publisher->id)
                ->first();

            if (! $site) {
                throw ValidationException::withMessages([
                    'order' => 'Unauthorized: This order does not belong to your site.',
                ]);
            }

            if ($order->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'order' => 'Order payment is not confirmed yet.',
                ]);
            }

            // Only after Accept (order is processing) and before advertiser review.
            if ($order->status !== 'processing') {
                throw ValidationException::withMessages([
                    'order' => 'You can only request a revised article after accepting the order and before advertiser review.',
                ]);
            }

            if ($locked->isModificationRequested()) {
                throw ValidationException::withMessages([
                    'order' => 'There is already an open live-URL change request from the advertiser.',
                ]);
            }

            $updating = $locked->isContentRevisionRequested();
            $previousReason = trim((string) ($locked->content_revision_reason ?? ''));

            if ($updating && $previousReason === $reason) {
                throw ValidationException::withMessages([
                    'reason' => 'Update the reason text before sending again.',
                ]);
            }

            $locked->update(array_merge([
                'content_revision_requested' => 'yes',
                'content_revision_requested_at' => $updating
                    ? ($locked->content_revision_requested_at ?? now())
                    : now(),
                'content_revision_reason' => $reason,
                'content_revision_resolved_at' => null,
            ], $this->liveUrlClearPayload()));

            $chatBody = $updating
                ? "Revised article request updated: {$reason}"
                : "Revised article requested: {$reason}\nPlease upload or link an updated article for this placement.";

            $this->postChat($order, $publisher->id, 'publisher', $chatBody);

            return [
                'item' => $locked->fresh(),
                'order' => $order->fresh(),
                'site' => $site,
                'updated' => $updating,
            ];
        });
    }

    /**
     * Notify advertiser after a successful publisher request (outside TX).
     */
    public function notifyAdvertiserRequested(
        Order $order,
        OrderItem $item,
        Site $site,
        string $reason,
        bool $updated = false,
    ): void {
        $advertiser = User::find($order->user_id);

        // First request: email + bell. Reason updates: bell only (avoid inbox spam).
        if (! $updated && $advertiser?->email) {
            try {
                Mail::to($advertiser->email)->send(new ContentRevisionRequested($order, $item, $site, $reason));
            } catch (\Throwable $e) {
                Log::error('Failed to send content revision requested email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->notifications->notifyContentRevisionRequested($order, $item, $site, $reason, $updated);
        } catch (\Throwable $e) {
            Log::error('Failed to bell content revision requested', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Advertiser fulfills by linking a new URL, confirming an edited library article,
     * and/or attaching an approved Content Library article.
     *
     * @param  array{content_link?: string|null, content_submission_id?: int|null, note?: string|null, order_item_id?: int|null, confirm_existing?: bool}  $payload
     * @return array{item: OrderItem, order: Order, site: Site}
     */
    public function fulfillFromAdvertiser(Order $order, User $advertiser, array $payload): array
    {
        if ((int) $order->user_id !== (int) $advertiser->id) {
            throw ValidationException::withMessages([
                'order' => 'Unauthorized',
            ]);
        }

        $contentLink = isset($payload['content_link']) ? trim((string) $payload['content_link']) : '';
        $submissionId = isset($payload['content_submission_id']) ? (int) $payload['content_submission_id'] : null;
        $note = isset($payload['note']) ? trim((string) $payload['note']) : '';
        $orderItemId = isset($payload['order_item_id']) ? (int) $payload['order_item_id'] : null;
        $confirmExisting = ! empty($payload['confirm_existing']);

        return DB::transaction(function () use ($order, $advertiser, $contentLink, $submissionId, $note, $orderItemId, $confirmExisting) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'order' => 'This order cannot be updated because payment is not complete.',
                ]);
            }

            $openItems = OrderItem::query()
                ->where('order_id', $lockedOrder->id)
                ->where('content_revision_requested', 'yes')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orderItemId) {
                $item = $openItems->firstWhere('id', $orderItemId);
            } elseif ($openItems->count() === 1) {
                $item = $openItems->first();
            } elseif ($openItems->count() > 1) {
                throw ValidationException::withMessages([
                    'order_item_id' => 'Please choose which placement to send the revised article for.',
                ]);
            } else {
                $item = null;
            }

            if (! $item) {
                throw ValidationException::withMessages([
                    'order' => 'There is no open content revision request on this order.',
                ]);
            }

            if (! in_array($lockedOrder->status, ['processing', 'review'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'This order is no longer waiting for a revised article.',
                ]);
            }

            $isLibraryItem = filled($item->content_submission_id);
            $update = [
                'content_revision_requested' => 'no',
                'content_revision_resolved_at' => now(),
            ];
            $chatExtra = '';

            if ($confirmExisting) {
                if (! $isLibraryItem) {
                    throw ValidationException::withMessages([
                        'confirm_existing' => 'This placement has no Content Library article to confirm. Paste a content link instead.',
                    ]);
                }

                $existing = ContentSubmission::query()
                    ->whereKey((int) $item->content_submission_id)
                    ->where('user_id', $advertiser->id)
                    ->first();

                if (! $existing || ! $existing->isApproved()) {
                    throw ValidationException::withMessages([
                        'confirm_existing' => 'The attached Content Library article is missing or no longer approved. Attach another approved article.',
                    ]);
                }

                if ($existing->hasImages() && ! $existing->imageRightsCoverContent()) {
                    throw ValidationException::withMessages([
                        'confirm_existing' => 'Confirm image rights on the attached article before sending it back.',
                    ]);
                }

                if (! $existing->hasCheckoutReadyLinks()) {
                    throw ValidationException::withMessages([
                        'confirm_existing' => 'Add a valid HTTPS target URL, or clear the link, before sending this article back.',
                    ]);
                }

                $update = array_merge($update, $this->submissionFieldsForItem($existing), $this->liveUrlClearPayload());
                $chatExtra = ' Confirmed the existing Content Library article was updated.';
            } elseif ($submissionId) {
                $submission = ContentSubmission::query()
                    ->whereKey($submissionId)
                    ->where('user_id', $advertiser->id)
                    ->first();

                if (! $submission) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'Content Library article not found.',
                    ]);
                }

                if (! $submission->isApproved()) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'Only approved Content Library articles can be attached.',
                    ]);
                }

                $sameAsCurrent = (int) $item->content_submission_id === (int) $submission->id;
                $linkedElsewhere = $submission->order_id
                    && (int) $submission->order_id !== (int) $lockedOrder->id;
                $usedBySibling = OrderItem::query()
                    ->where('order_id', $lockedOrder->id)
                    ->where('id', '!=', $item->id)
                    ->where('content_submission_id', $submission->id)
                    ->exists();

                if (! $sameAsCurrent && ($linkedElsewhere || $usedBySibling)) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'That Content Library article is already used on another placement.',
                    ]);
                }

                if (! $sameAsCurrent && $submission->order_id === null && ! $submission->canBeOrdered()) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'That Content Library article is not available to attach.',
                    ]);
                }

                if (! $submission->hasCheckoutReadyLinks()) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'Add a valid HTTPS target URL, or clear the link, before attaching this article.',
                    ]);
                }

                $previousSubmissionId = $item->content_submission_id
                    ? (int) $item->content_submission_id
                    : null;

                $update = array_merge($update, $this->submissionFieldsForItem($submission), $this->liveUrlClearPayload());
                $this->relinkSubmission($submission, $lockedOrder, $item, $previousSubmissionId);
                $chatExtra = $sameAsCurrent
                    ? ' Confirmed the existing Content Library article was updated.'
                    : ' Attached a Content Library article.';
            } elseif ($contentLink !== '') {
                if ($isLibraryItem) {
                    throw ValidationException::withMessages([
                        'content_link' => 'This placement uses Content Library. Confirm the existing article was edited, or attach another approved library article.',
                    ]);
                }

                $update = array_merge($update, $this->liveUrlClearPayload(), [
                    'content_link' => $contentLink,
                    'content_submission_id' => null,
                    'content_disk' => null,
                    'content_path' => null,
                    'content_original_name' => null,
                    'content_mime' => null,
                ]);
                $chatExtra = ' New content link provided.';
            } else {
                $message = $isLibraryItem
                    ? 'Confirm you edited the existing Content Library article, or attach another approved article.'
                    : 'Provide a content link or choose an approved Content Library article.';

                throw ValidationException::withMessages([
                    'content' => $message,
                ]);
            }

            $item->update($update);
            $this->maybePromoteOrderToReview($lockedOrder);

            $site = Site::find($item->site_id);
            $chatBody = 'Revised article sent.'.$chatExtra.($note !== '' ? "\nNote: {$note}" : '');
            $this->postChat($lockedOrder, $advertiser->id, 'advertiser', $chatBody);

            return [
                'item' => $item->fresh(),
                'order' => $lockedOrder->fresh(),
                'site' => $site,
            ];
        });
    }

    public function notifyPublisherFulfilled(Order $order, OrderItem $item, ?Site $site): void
    {
        if (! $site?->publisher_id) {
            return;
        }

        $publisher = User::find($site->publisher_id);
        if ($publisher?->email) {
            try {
                Mail::to($publisher->email)->send(new ContentRevisionFulfilled($order, $item, $site));
            } catch (\Throwable $e) {
                Log::error('Failed to send content revision fulfilled email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->notifications->notifyContentRevisionFulfilled($order, $item, $site);
        } catch (\Throwable $e) {
            Log::error('Failed to bell content revision fulfilled', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * After a content revision is cleared, promote to review only when every line
     * has a fresh live URL (submitted after any revision resolve). Otherwise keep
     * or return the order to processing so the publisher can re-publish.
     */
    private function maybePromoteOrderToReview(Order $order): void
    {
        if (! in_array($order->status, ['processing', 'review'], true)) {
            return;
        }

        $items = OrderItem::query()->where('order_id', $order->id)->get();
        if ($items->isEmpty()) {
            return;
        }

        $readyForReview = true;
        foreach ($items as $line) {
            if ($line->isContentRevisionRequested() || $line->isModificationRequested() || ! $this->lineHasFreshLiveUrlForReview($line)) {
                $readyForReview = false;
                break;
            }
        }

        if ($readyForReview) {
            if ($order->status === 'processing') {
                $order->update(['status' => 'review']);
                // Sibling live URLs may have been submitted while this order was held
                // in processing for a content revision — restart the review window.
                OrderItem::restartAutoApproveClocksForOrder((int) $order->id);
            } elseif ($order->status === 'review') {
                OrderItem::restartAutoApproveClocksForOrder((int) $order->id);
            }

            return;
        }

        // Fulfill clears the placement live URL so the publisher can re-publish the
        // revised article. If admin had forced review while a revision was open,
        // drop back to processing once the revision is cleared.
        if ($order->status === 'review' && ! OrderItem::orderHasOpenContentRevision((int) $order->id)) {
            $order->update(['status' => 'processing']);
        }
    }

    /**
     * A live URL submitted before a content revision was fulfilled is stale —
     * the publisher must re-publish and submit again after the new article.
     */
    private function lineHasFreshLiveUrlForReview(OrderItem $line): bool
    {
        if (! filled($line->live_url)) {
            return false;
        }

        if ($line->content_revision_resolved_at) {
            $submittedAt = $line->live_url_submitted_at;
            if (! $submittedAt || $submittedAt->lte($line->content_revision_resolved_at)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop any prior live URL so review cannot start on a pre-revision publish.
     *
     * @return array<string, mixed>
     */
    private function liveUrlClearPayload(): array
    {
        $table = (new OrderItem)->getTable();
        $payload = [
            'live_url' => null,
        ];

        if (Schema::hasColumn($table, 'live_url_submitted_at')) {
            $payload['live_url_submitted_at'] = null;
        }
        if (Schema::hasColumn($table, 'live_url_check_ok')) {
            $payload['live_url_check_ok'] = null;
        }
        if (Schema::hasColumn($table, 'live_url_http_status')) {
            $payload['live_url_http_status'] = null;
        }
        if (Schema::hasColumn($table, 'live_url_checked_at')) {
            $payload['live_url_checked_at'] = null;
        }
        if (Schema::hasColumn($table, 'auto_approve_triggered')) {
            $payload['auto_approve_triggered'] = false;
        }
        if (Schema::hasColumn($table, 'auto_approve_reminder_sent_at')) {
            $payload['auto_approve_reminder_sent_at'] = null;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionFieldsForItem(ContentSubmission $submission): array
    {
        $fields = [
            'content_submission_id' => $submission->id,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'content_mime' => $submission->mime,
            'content_link' => route('advertiser.content-submissions.download', $submission),
        ];

        if (filled($submission->anchor_text)) {
            $fields['anchor_text'] = $submission->anchor_text;
        }
        if (filled($submission->target_url)) {
            $fields['target_url'] = $submission->target_url;
        }
        if (filled($submission->feature_image_url)) {
            $fields['feature_image_url'] = $submission->feature_image_url;
        }
        if (filled($submission->moderation_status)) {
            $fields['moderation_status'] = $submission->moderation_status;
        }

        return $fields;
    }

    private function relinkSubmission(
        ContentSubmission $submission,
        Order $order,
        OrderItem $item,
        ?int $previousSubmissionId,
    ): void {
        if ($previousSubmissionId && $previousSubmissionId !== (int) $submission->id) {
            $release = [
                'order_id' => null,
                'order_item_id' => null,
            ];
            $filteredRelease = app(CheckoutSchemaService::class)
                ->filterExistingColumns((new ContentSubmission)->getTable(), $release);

            if ($filteredRelease !== []) {
                ContentSubmission::query()
                    ->whereKey($previousSubmissionId)
                    ->where('user_id', $order->user_id)
                    ->where('order_id', $order->id)
                    ->update($filteredRelease);
            }
        }

        $payload = [
            'publication_mode' => $order->publication_mode,
            'scheduled_publish_at' => $order->scheduled_publish_at,
            'timezone' => $order->schedule_timezone ?: $submission->timezone,
        ];

        if (! $submission->order_id || (int) $submission->order_id === (int) $order->id) {
            $payload['order_id'] = $order->id;
            $payload['order_item_id'] = $item->id;
        }

        $filtered = app(CheckoutSchemaService::class)
            ->filterExistingColumns($submission->getTable(), $payload);

        if ($filtered !== []) {
            $submission->update($filtered);
        }
    }

    private function postChat(Order $order, int $userId, string $senderType, string $body): void
    {
        try {
            $guard = $this->chatGuard->inspect($body);
            OrderChatMessage::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'sender_type' => $senderType,
                'message' => $body,
                'is_read' => false,
                'is_blocked' => (bool) $guard['blocked'],
                'blocked_reason' => $guard['blocked'] ? $guard['reason'] : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create content revision chat message', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
