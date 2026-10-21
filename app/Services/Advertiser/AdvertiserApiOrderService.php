<?php

namespace App\Services\Advertiser;

use App\Exceptions\AdvertiserApiCannotCancelException;
use App\Exceptions\AdvertiserApiInsufficientFundsException;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CartPricingService;
use App\Services\CheckoutSchemaService;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\ArticleWritingService;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\WritingFeeService;
use App\Services\Mcp\MarketplaceListing;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdvertiserApiOrderService
{
    public function __construct(
        private CartPricingService $pricing,
        private CheckoutAddOnService $addons,
        private CheckoutSchemaService $schema,
        private MarketplaceListing $listing,
        private AdvertiserApiArticleService $articles,
        private WritingFeeService $writing,
        private WalletLedgerService $ledger,
        private AdvertiserApiPublisherNotifier $publishers,
        private InAppNotificationService $notifications,
        private AdvertiserApiSerializer $serializer,
        private OrderRefundService $refunds,
        private ScheduledOrderService $scheduler,
    ) {}

    /**
     * @param  array{site_id: int, anchor: string, target_url: string, content?: ?string, content_format?: string, writing?: bool, link_guarantee?: bool}  $input
     */
    public function create(User $user, array $input, bool $sandbox): Order
    {
        $site = $this->listing->findVisibleSite($input['site_id'] ?? null);
        if (! $site || $site->isOwnedBy($user)) {
            throw new RuntimeException('site_not_found');
        }

        $anchor = trim((string) ($input['anchor'] ?? ''));
        $target = trim((string) ($input['target_url'] ?? ''));
        $wantWriting = (bool) ($input['writing'] ?? false);
        $wantGuarantee = (bool) ($input['link_guarantee'] ?? false);
        $content = trim((string) ($input['content'] ?? ''));
        $format = strtolower((string) ($input['content_format'] ?? 'html'));
        if (! in_array($format, ['html', 'markdown'], true)) {
            $format = 'html';
        }

        if ($wantGuarantee && ! $this->addons->siteQualifiesForGuarantee($site)) {
            throw new RuntimeException('guarantee_not_offered');
        }

        if (! $wantWriting && $content === '') {
            throw new RuntimeException('content_or_writing_required');
        }

        if ($anchor === '' || $target === '') {
            throw new RuntimeException('anchor_and_target_required');
        }
        if (! preg_match('#^https://#i', $target)) {
            throw new RuntimeException('target_url_https_required');
        }

        if ($sandbox) {
            return $this->createSandbox($user, $site, $anchor, $target, $wantWriting, $wantGuarantee, $content, $format);
        }

        return $this->createLive($user, $site, $anchor, $target, $wantWriting, $wantGuarantee, $content, $format);
    }

    public function cancel(User $user, Order $order, bool $sandbox): Order
    {
        if ((int) $order->user_id !== (int) $user->id) {
            throw new RuntimeException('order_not_found');
        }
        if ($sandbox !== AdvertiserSandbox::isSandboxOrder($order)) {
            throw new RuntimeException('order_not_found');
        }
        if (! $this->serializer->cancellable($order)) {
            throw new AdvertiserApiCannotCancelException('This order can no longer be cancelled.');
        }

        if ($sandbox) {
            $order->status = 'cancelled';
            $order->payment_status = 'refunded';
            $order->save();

            return $order->fresh(['items']);
        }

        if (($order->publication_mode ?? '') === 'scheduled') {
            $this->scheduler->cancelUpcoming($order, $this->refunds);
        } else {
            $this->refunds->cancelAndRefund($order, 'Cancelled by advertiser API');
        }

        return $order->fresh(['items']);
    }

    /**
     * @param  Builder|\Illuminate\Database\Eloquent\Builder<Order>  $query
     * @return LengthAwarePaginator<Order>
     */
    public function paginate(User $user, bool $sandbox, int $perPage = 20)
    {
        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['items']);
        AdvertiserSandbox::constrain($query, $sandbox);

        return $query->orderByDesc('id')->paginate(max(1, min(50, $perPage)));
    }

    public function findOwn(User $user, int $id, bool $sandbox): ?Order
    {
        $query = Order::query()->where('user_id', $user->id)->whereKey($id)->with(['items']);
        AdvertiserSandbox::constrain($query, $sandbox);

        return $query->first();
    }

    private function createLive(
        User $user,
        Site $site,
        string $anchor,
        string $target,
        bool $wantWriting,
        bool $wantGuarantee,
        string $content,
        string $format,
    ): Order {
        $line = $this->priceLine($user, $site, $wantWriting, $wantGuarantee, $anchor, $target);
        $total = round((float) $line['price'], 2);
        $reference = 'API-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $submission = null;
        if (! $wantWriting) {
            $submission = $this->articles->createFromText($user, $site, $content, $anchor, $target, $format);
        }

        $created = DB::transaction(function () use ($user, $site, $line, $total, $reference, $submission, $anchor, $target) {
            $roleId = Wallet::advertiserRoleId();
            if (! $roleId) {
                throw new RuntimeException('wallet_unavailable');
            }
            $wallet = Wallet::lockOrCreateForRole((int) $user->id, (int) $roleId);
            $wallet->repairOrphanedWelcomeBonus();
            $wallet->reconcileInflatedBonusBalance();
            $wallet->refresh();
            if ($reason = $wallet->advertiserSpendBlockedReason()) {
                throw new RuntimeException($reason);
            }

            $spendable = round((float) $wallet->balance, 2);
            if ($spendable < $total) {
                throw new AdvertiserApiInsufficientFundsException($total, $spendable);
            }

            $bonusUsed = $wallet->reserveForOrder($total, true);
            $this->ledger->recordPurchase($wallet, $total, $bonusUsed, null, $reference);

            $this->schema->ensureCheckoutTables();
            $order = Order::query()->create($this->schema->filterExistingColumns('orders', array_merge([
                'user_id' => $user->id,
                'order_number' => Order::nextOrderNumber(),
                'reference_code' => $reference,
                'subtotal' => $total,
                'tax' => 0,
                'total_amount' => $total,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'pending',
                'sensitive_type' => $line['sensitive_type'] ?? null,
                'additional_price' => $line['additional_price'] ?? 0,
                'paid_at' => now(),
            ], $this->addons->orderColumns($line))));

            $item = OrderItem::query()->create($this->schema->filterExistingColumns(
                'order_items',
                $this->itemPayload($order->id, $site, $line, $submission, $anchor, $target)
            ));
            app(ArticleWritingService::class)->initializePaidItem($item, $site);

            if ($submission instanceof ContentSubmission) {
                $submission->forceFill([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                ])->save();
            }

            $purchaseTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', WalletTransaction::TYPE_PURCHASE)
                ->where('reference', $reference)
                ->latest('id')
                ->first();
            if ($purchaseTx && ! $purchaseTx->related_id) {
                $purchaseTx->update([
                    'related_type' => $order->getMorphClass(),
                    'related_id' => $order->id,
                    'meta' => array_merge((array) $purchaseTx->meta, [
                        'order_ids' => [$order->id],
                        'order_reference' => $reference,
                    ]),
                ]);
            }

            return $order->fresh(['items']);
        });

        try {
            $this->notifications->notifyOrderCreated($created);
        } catch (\Throwable $e) {
            Log::warning('Advertiser API notifyOrderCreated failed: '.$e->getMessage());
        }
        try {
            $this->publishers->notify([$created]);
        } catch (\Throwable $e) {
            Log::warning('Advertiser API publisher notify failed: '.$e->getMessage());
        }

        return $created;
    }

    private function createSandbox(
        User $user,
        Site $site,
        string $anchor,
        string $target,
        bool $wantWriting,
        bool $wantGuarantee,
        string $content,
        string $format,
    ): Order {
        if (! AdvertiserSandbox::schemaReady()) {
            throw new RuntimeException('sandbox_unavailable');
        }
        $line = $this->priceLine($user, $site, $wantWriting, $wantGuarantee, $anchor, $target);
        $submission = null;
        if (! $wantWriting && $content !== '') {
            $submission = $this->articles->createFromText($user, $site, $content, $anchor, $target, $format);
        }

        $order = DB::transaction(function () use ($user, $site, $line, $submission, $anchor, $target) {
            $orderPayload = [
                'user_id' => $user->id,
                'order_number' => 'SB'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                'reference_code' => 'SBX-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                'subtotal' => 0,
                'tax' => 0,
                'total_amount' => 0,
                'payment_method' => 'sandbox',
                'payment_status' => 'paid',
                'status' => 'pending',
                'paid_at' => now(),
                'is_sandbox' => true,
            ];
            $order = Order::query()->create($this->schema->filterExistingColumns('orders', $orderPayload));
            $itemPayload = $this->itemPayload($order->id, $site, $line, $submission, $anchor, $target);
            $itemPayload['price'] = 0;
            $itemPayload['advertiser_eur_amount'] = 0;
            $itemPayload['publisher_amount'] = 0;
            $itemPayload['publisher_price'] = 0;
            $itemPayload['platform_fee_amount'] = 0;
            $itemPayload['is_sandbox'] = true;
            $item = OrderItem::query()->create($this->schema->filterExistingColumns('order_items', $itemPayload));
            if ($submission instanceof ContentSubmission) {
                $submission->forceFill([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                ])->save();
            }

            return $order->fresh(['items']);
        });

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function priceLine(User $user, Site $site, bool $wantWriting, bool $wantGuarantee, string $anchor, string $target): array
    {
        $expanded = $this->pricing->expandCart([
            ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
        ], (int) $user->id);
        $orderItem = $expanded[0];
        $cartLine = [
            'id' => $site->id,
            'content_source' => $wantWriting ? $this->addons->requestedWriter($site) : WritingFeeService::SOURCE_BUYER,
            'writing_anchor_text' => $anchor,
            'writing_target_url' => $target,
        ];

        return $this->addons->decorateOrderItem($orderItem, $cartLine, $wantGuarantee);
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    private function itemPayload(
        int $orderId,
        Site $site,
        array $orderItem,
        ?ContentSubmission $submission,
        string $anchor,
        string $target,
    ): array {
        $addons = $this->addons->orderItemColumns($orderItem);

        return array_merge([
            'order_id' => $orderId,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $orderItem['price'],
            'content_link' => $submission
                ? 'api-article'
                : (CheckoutAddOnService::isWrittenSource($orderItem) ? OrderItem::PENDING_WRITTEN_LINK : null),
            'content_submission_id' => $submission?->id,
            'content_disk' => $submission?->disk,
            'content_path' => $submission?->path,
            'content_original_name' => $submission?->original_filename,
            'content_mime' => $submission?->mime,
            'anchor_text' => $submission?->anchor_text ?: $anchor,
            'target_url' => $submission?->target_url ?: $target,
            'feature_image_url' => $submission?->feature_image_url,
            'moderation_status' => $submission?->moderation_status,
            'sensitive_type' => $orderItem['sensitive_type'] ?? null,
            'additional_price' => $orderItem['additional_price'] ?? 0,
            'homepage_days' => $orderItem['homepage_days'] ?? null,
            'homepage_price' => $orderItem['homepage_price'] ?? 0,
            'social_channels' => $orderItem['social_channels'] ?? ($site->enabledSocialChannels() ?: []),
            'publisher_price' => $orderItem['publisher_price'] ?? null,
            'platform_fee_percent' => $orderItem['platform_fee_percent'] ?? null,
            'platform_fee_amount' => $orderItem['platform_fee_amount'] ?? null,
            ...OrderItem::fxSnapshotFromPricing($orderItem),
        ], $addons);
    }
}
