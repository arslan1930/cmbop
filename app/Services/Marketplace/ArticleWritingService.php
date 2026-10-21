<?php

namespace App\Services\Marketplace;

use App\Mail\ArticleApprovedForPublishMail;
use App\Mail\ArticleReadyForApprovalMail;
use App\Mail\ArticleRevisionRequestedMail;
use App\Mail\WritingDeadlineReminderMail;
use App\Models\InAppNotification;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Written-article workflow (publisher or SLB) before a placement is published.
 */
class ArticleWritingService
{
    public const STATUS_AWAITING_WRITER = 'awaiting_writer';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_ARTICLE_SUBMITTED = 'article_submitted';

    public const STATUS_ARTICLE_REVISION = 'article_revision';

    public const STATUS_ARTICLE_APPROVED = 'article_approved';

    public const STATUS_RELEASED = 'released_to_publisher';

    public function __construct(private InAppNotificationService $notifications) {}

    public function isWritingLine(OrderItem $item): bool
    {
        $source = (string) ($item->content_source ?? WritingFeeService::SOURCE_BUYER);

        return in_array($source, [WritingFeeService::SOURCE_PUBLISHER, WritingFeeService::SOURCE_SLB], true);
    }

    public function isSlbWritten(OrderItem $item): bool
    {
        return ($item->content_source ?? '') === WritingFeeService::SOURCE_SLB;
    }

    public function isPublisherWritten(OrderItem $item): bool
    {
        return ($item->content_source ?? '') === WritingFeeService::SOURCE_PUBLISHER;
    }

    public function publisherCanSee(OrderItem $item): bool
    {
        if (! $this->isWritingLine($item)) {
            return true;
        }
        if ($this->isPublisherWritten($item)) {
            return true;
        }

        return $this->isReleasedToPublisher($item);
    }

    public function isReleasedToPublisher(OrderItem $item): bool
    {
        $status = (string) ($item->writing_status ?? '');

        return in_array($status, [self::STATUS_ARTICLE_APPROVED, self::STATUS_RELEASED], true);
    }

    public function awaitingAdvertiserArticleApproval(OrderItem $item): bool
    {
        return $this->isWritingLine($item)
            && (string) ($item->writing_status ?? '') === self::STATUS_ARTICLE_SUBMITTED;
    }

    public function canRequestArticleRevision(OrderItem $item): bool
    {
        return $this->awaitingAdvertiserArticleApproval($item)
            && (int) ($item->article_revision_used ?? 0) < 1;
    }

    public function initializePaidItem(OrderItem $item, ?Site $site = null): void
    {
        if (! Schema::hasColumn('order_items', 'writing_status') || ! $this->isWritingLine($item)) {
            return;
        }

        $site ??= $item->relationLoaded('site') ? $item->site : Site::query()->find($item->site_id);
        $days = max(1, (int) config('orders.writing.deadline_days', 5));
        $language = $item->writing_language
            ?: (is_string($site?->language) ? $site->language : null);

        $item->forceFill(array_filter([
            'writing_status' => self::STATUS_AWAITING_WRITER,
            'writing_deadline_at' => now()->addDays($days),
            'writing_language' => $language,
        ], fn ($value) => $value !== null))->save();
    }

    public function assign(OrderItem $item, User $admin, ?Carbon $deadline = null): OrderItem
    {
        if (! $this->isSlbWritten($item)) {
            throw new RuntimeException('Only SEOLinkBuildings-written orders can be assigned in the writing queue.');
        }
        if (! in_array((string) $item->writing_status, [self::STATUS_AWAITING_WRITER, self::STATUS_ASSIGNED, self::STATUS_ARTICLE_REVISION], true)) {
            throw new RuntimeException('This article is not waiting for a writer.');
        }

        $item->forceFill([
            'writing_status' => self::STATUS_ASSIGNED,
            'writing_assignee_id' => $admin->id,
            'writing_deadline_at' => $deadline ?: ($item->writing_deadline_at ?: now()->addDays(5)),
        ])->save();

        ActivityLogger::tryLog(
            'writing.assigned',
            'Writing task assigned for order #'.($item->order?->order_number ?? $item->order_id),
            $item,
            ['assignee_id' => $admin->id],
            'Order #'.($item->order?->order_number ?? $item->order_id)
        );

        return $item->fresh() ?? $item;
    }

    /**
     * @param  array{disk?: string, path?: string, name?: string, mime?: string}  $file
     */
    public function submitArticle(OrderItem $item, array $file, User $actor): OrderItem
    {
        if (! $this->isWritingLine($item)) {
            throw new RuntimeException('This placement does not need a written article.');
        }

        $status = (string) ($item->writing_status ?? '');
        if (! in_array($status, [self::STATUS_AWAITING_WRITER, self::STATUS_ASSIGNED, self::STATUS_ARTICLE_REVISION], true)) {
            throw new RuntimeException('This article cannot be submitted in its current status.');
        }

        if ($this->isSlbWritten($item) && (int) ($item->writing_assignee_id ?? 0) === 0) {
            $item->writing_assignee_id = $actor->id;
        }

        $item->forceFill([
            'content_disk' => $file['disk'] ?? $item->content_disk,
            'content_path' => $file['path'] ?? $item->content_path,
            'content_original_name' => $file['name'] ?? $item->content_original_name,
            'content_mime' => $file['mime'] ?? $item->content_mime,
            'content_link' => null,
            'writing_status' => self::STATUS_ARTICLE_SUBMITTED,
            'article_submitted_at' => now(),
        ])->save();

        $this->notifyAdvertiserArticleReady($item);

        return $item->fresh() ?? $item;
    }

    public function storeUploadedArticle(UploadedFile $file, OrderItem $item): array
    {
        $dir = 'order-writing/'.$item->id;
        $path = $file->store($dir, 'local');

        return [
            'disk' => 'local',
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType() ?: $file->getMimeType(),
        ];
    }

    public function approveArticle(OrderItem $item, User $advertiser): OrderItem
    {
        if (! $this->awaitingAdvertiserArticleApproval($item)) {
            throw new RuntimeException('This article is not waiting for your approval.');
        }

        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if (! $order || (int) $order->user_id !== (int) $advertiser->id) {
            throw new RuntimeException('Unauthorized');
        }

        $next = $this->isSlbWritten($item) ? self::STATUS_RELEASED : self::STATUS_ARTICLE_APPROVED;
        $item->forceFill([
            'writing_status' => $next,
            'article_approved_at' => now(),
        ])->save();

        $this->notifyWriterArticleApproved($item);

        return $item->fresh() ?? $item;
    }

    public function requestRevision(OrderItem $item, User $advertiser, string $reason): OrderItem
    {
        if (! $this->canRequestArticleRevision($item)) {
            throw new RuntimeException('A free revision is no longer available for this article.');
        }

        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if (! $order || (int) $order->user_id !== (int) $advertiser->id) {
            throw new RuntimeException('Unauthorized');
        }

        $item->forceFill([
            'writing_status' => self::STATUS_ARTICLE_REVISION,
            'article_revision_used' => 1,
            'content_revision_reason' => $reason,
        ])->save();

        $this->notifyWriterRevisionRequested($item, $reason);

        return $item->fresh() ?? $item;
    }

    /**
     * @return Builder<OrderItem>
     */
    public function queueQuery(?string $status = null)
    {
        $query = OrderItem::query()
            ->where('content_source', WritingFeeService::SOURCE_SLB)
            ->whereHas('order', fn ($order) => $order->where('payment_status', 'paid')->where('status', '!=', 'cancelled'))
            ->with(['order.user', 'site', 'assignee']);

        if ($status) {
            $query->where('writing_status', $status);
        } else {
            $query->whereIn('writing_status', [
                self::STATUS_AWAITING_WRITER,
                self::STATUS_ASSIGNED,
                self::STATUS_ARTICLE_SUBMITTED,
                self::STATUS_ARTICLE_REVISION,
            ]);
        }

        return $query->orderBy('writing_deadline_at')->orderBy('id');
    }

    public function remindDueWriters(int $limit = 40): int
    {
        if (! Schema::hasColumn('order_items', 'writing_reminder_sent_at')) {
            return 0;
        }

        $items = OrderItem::query()
            ->where('content_source', WritingFeeService::SOURCE_SLB)
            ->whereIn('writing_status', [self::STATUS_AWAITING_WRITER, self::STATUS_ASSIGNED, self::STATUS_ARTICLE_REVISION])
            ->whereNotNull('writing_deadline_at')
            ->where('writing_deadline_at', '<=', now()->addDay())
            ->whereNull('writing_reminder_sent_at')
            ->with(['order', 'site'])
            ->limit(max(1, min(200, $limit)))
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $this->notifyWritingDeadline($item);
            $item->update(['writing_reminder_sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    public function writingDownloadResponse(OrderItem $item)
    {
        if (! $item->hasDownloadableContent() && ! filled($item->content_path)) {
            abort(404);
        }

        $disk = $item->content_disk ?: 'local';
        $path = (string) $item->content_path;
        if (! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        return Storage::disk($disk)->download($path, $item->content_original_name ?: 'article');
    }

    private function notifyAdvertiserArticleReady(OrderItem $item): void
    {
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        $advertiser = $order?->user_id ? User::query()->find($order->user_id) : null;
        if (! $order || ! $advertiser) {
            return;
        }

        $this->notifications->notify(
            $advertiser,
            InAppNotificationService::TYPE_ORDER_UPDATED,
            'Article ready for approval — order #'.$order->order_number,
            'Review the written article. You can approve it or request one free revision.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'icon' => 'file-alt',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'action_label' => 'Review article',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => ['order_number' => $order->order_number, 'order_item_id' => $item->id],
            ]
        );

        if ($advertiser->email) {
            try {
                Mail::to($advertiser->email)->send(new ArticleReadyForApprovalMail($advertiser, $order, $item));
            } catch (\Throwable $e) {
                Log::warning('Article ready mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notifyWriterArticleApproved(OrderItem $item): void
    {
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if (! $order) {
            return;
        }

        if ($this->isSlbWritten($item)) {
            $site = $item->relationLoaded('site') ? $item->site : Site::query()->find($item->site_id);
            $publisher = $site?->publisher_id ? User::query()->find($site->publisher_id) : null;
            if ($publisher) {
                $this->notifications->notify(
                    $publisher,
                    InAppNotificationService::TYPE_ORDER_CREATED,
                    'Article ready to publish — order #'.$order->order_number,
                    'SEOLinkBuildings delivered the approved article. Publish it on '.$item->site_name.'.',
                    [
                        'category' => InAppNotificationService::CATEGORY_ORDERS,
                        'icon' => 'package',
                        'priority' => InAppNotification::PRIORITY_HIGH,
                        'related' => $order,
                        'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                        'action_label' => 'Open task',
                        'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                    ]
                );
                if ($publisher->email) {
                    try {
                        Mail::to($publisher->email)->send(new ArticleApprovedForPublishMail($publisher, $order, $item));
                    } catch (\Throwable $e) {
                        Log::warning('Article approved publish mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    }
                }
            }

            return;
        }

        $site = $item->relationLoaded('site') ? $item->site : Site::query()->find($item->site_id);
        $publisher = $site?->publisher_id ? User::query()->find($site->publisher_id) : null;
        if ($publisher) {
            $this->notifications->notify(
                $publisher,
                InAppNotificationService::TYPE_ORDER_UPDATED,
                'Article approved — publish order #'.$order->order_number,
                'The advertiser approved your article. Publish it on the live site.',
                [
                    'category' => InAppNotificationService::CATEGORY_ORDERS,
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        }
    }

    private function notifyWriterRevisionRequested(OrderItem $item, string $reason): void
    {
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if (! $order) {
            return;
        }

        $recipient = null;
        if ($this->isSlbWritten($item) && $item->writing_assignee_id) {
            $recipient = User::query()->find($item->writing_assignee_id);
        }
        if (! $recipient) {
            $site = $item->relationLoaded('site') ? $item->site : Site::query()->find($item->site_id);
            $recipient = $site?->publisher_id ? User::query()->find($site->publisher_id) : null;
        }
        if (! $recipient) {
            return;
        }

        $this->notifications->notify(
            $recipient,
            InAppNotificationService::TYPE_CONTENT_REVISION_REQUESTED,
            'Revision requested — order #'.$order->order_number,
            $reason,
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'action_url' => $this->isSlbWritten($item)
                    ? route('admin.writing-queue.show', $item, false)
                    : route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
            ]
        );

        if ($recipient->email) {
            try {
                Mail::to($recipient->email)->send(new ArticleRevisionRequestedMail($recipient, $order, $item, $reason));
            } catch (\Throwable $e) {
                Log::warning('Article revision mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notifyWritingDeadline(OrderItem $item): void
    {
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        $assignee = $item->writing_assignee_id ? User::query()->find($item->writing_assignee_id) : null;
        $admins = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
        $targets = $assignee ? collect([$assignee]) : $admins;

        foreach ($targets as $user) {
            if (! $user instanceof User || ! $order) {
                continue;
            }
            $this->notifications->notify(
                $user,
                InAppNotificationService::TYPE_ORDER_UPDATED,
                'Writing deadline soon — order #'.$order->order_number,
                'The written article for '.$item->site_name.' is due soon.',
                [
                    'category' => InAppNotificationService::CATEGORY_ORDERS,
                    'related' => $order,
                    'action_url' => route('admin.writing-queue.show', $item, false),
                ]
            );
            if ($user->email) {
                try {
                    Mail::to($user->email)->send(new WritingDeadlineReminderMail($user, $order, $item));
                } catch (\Throwable $e) {
                    Log::warning('Writing deadline mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
