<?php

namespace App\Services\Marketplace;

use App\Mail\LinkGuaranteeRestoreMail;
use App\Models\InAppNotification;
use App\Models\LinkCheck;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class LinkGuaranteeService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESTORE_DUE = 'restore_due';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        private LinkPresenceChecker $checker,
        private OrderRefundService $refunds,
        private WalletLedgerService $ledger,
        private InAppNotificationService $notifications,
    ) {}

    /**
     * @return array{checked: int, missing: int, restore_notified: int, refunded: int, skipped: int}
     */
    public function runDueChecks(int $limit = 40): array
    {
        $checked = 0;
        $missing = 0;
        $restoreNotified = 0;
        $refunded = 0;
        $skipped = 0;

        if (! Schema::hasColumn('orders', 'link_guarantee') || ! LinkCheck::tableAvailable()) {
            return compact('checked', 'missing', 'restoreNotified', 'refunded', 'skipped');
        }

        $limit = max(1, min(200, $limit));
        $intervalDays = max(1, (int) config('orders.link_guarantee.check_interval_days', 28));
        $staleBefore = now()->subDays($intervalDays);

        $orders = Order::query()
            ->where('link_guarantee', true)
            ->where('payment_status', 'paid')
            ->whereIn('link_guarantee_status', [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_RESTORE_DUE])
            ->with(['items', 'user'])
            ->orderBy('id')
            ->limit($limit * 3)
            ->get();

        foreach ($orders as $order) {
            if ($checked >= $limit) {
                break;
            }

            $this->activateIfCompleted($order);
            $order->refresh();

            if ($order->link_guarantee_status === self::STATUS_PENDING) {
                $skipped++;

                continue;
            }

            if ($order->link_guarantee_status === self::STATUS_REFUNDED
                || $order->link_guarantee_status === self::STATUS_EXPIRED) {
                $skipped++;

                continue;
            }

            if ($order->link_guarantee_ends_at && $order->link_guarantee_ends_at->isPast()) {
                $order->update(['link_guarantee_status' => self::STATUS_EXPIRED]);
                $skipped++;

                continue;
            }

            $item = $order->items->first();
            if (! $item instanceof OrderItem || ! filled($item->live_url) || ! filled($item->target_url)) {
                $skipped++;

                continue;
            }

            $last = LinkCheck::query()
                ->where('order_item_id', $item->id)
                ->orderByDesc('id')
                ->first();

            $due = ! $last
                || $last->checked_at === null
                || $last->checked_at->lte($staleBefore)
                || ($order->link_guarantee_status === self::STATUS_RESTORE_DUE
                    && $order->link_guarantee_restore_due_at
                    && $order->link_guarantee_restore_due_at->isPast());

            if (! $due) {
                $skipped++;

                continue;
            }

            $result = $this->checker->check((string) $item->live_url, (string) $item->target_url);
            $checked++;

            $reachable = $result['status'] !== null
                && $result['status'] >= 200
                && $result['status'] < 400;
            if (! $reachable) {
                LinkCheck::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'checked_at' => now(),
                    'status' => LinkCheck::STATUS_ERROR,
                    'http_code' => $result['status'],
                    'href_found' => false,
                    'notes' => $result['message'],
                ]);
                $skipped++;

                continue;
            }

            if ($result['ok']) {
                LinkCheck::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'checked_at' => now(),
                    'status' => $order->link_guarantee_status === self::STATUS_RESTORE_DUE
                        ? LinkCheck::STATUS_RESTORED
                        : LinkCheck::STATUS_OK,
                    'http_code' => $result['status'],
                    'href_found' => true,
                    'notes' => $result['message'],
                ]);
                if ($order->link_guarantee_status === self::STATUS_RESTORE_DUE) {
                    $order->update([
                        'link_guarantee_status' => self::STATUS_ACTIVE,
                        'link_guarantee_restore_due_at' => null,
                    ]);
                }

                continue;
            }

            $missing++;
            if ($order->link_guarantee_status === self::STATUS_RESTORE_DUE
                && $order->link_guarantee_restore_due_at
                && $order->link_guarantee_restore_due_at->isPast()) {
                if ($this->refundCoveredAmount($order, $item, $result['message'])) {
                    $refunded++;
                    LinkCheck::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'checked_at' => now(),
                        'status' => LinkCheck::STATUS_REFUNDED,
                        'http_code' => $result['status'],
                        'href_found' => false,
                        'notes' => $result['message'],
                    ]);
                }

                continue;
            }

            if ($order->link_guarantee_status !== self::STATUS_RESTORE_DUE) {
                $dueAt = now()->addDays(max(1, (int) config('orders.link_guarantee.restore_days', 7)));
                $order->update([
                    'link_guarantee_status' => self::STATUS_RESTORE_DUE,
                    'link_guarantee_restore_due_at' => $dueAt,
                ]);
                $this->notifyPublisherToRestore($order, $item, $dueAt);
                $restoreNotified++;
            }

            LinkCheck::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'checked_at' => now(),
                'status' => LinkCheck::STATUS_RESTORE_REQUESTED,
                'http_code' => $result['status'],
                'href_found' => false,
                'notes' => $result['message'],
                'restore_due_at' => $order->link_guarantee_restore_due_at,
            ]);
        }

        return compact('checked', 'missing', 'restoreNotified', 'refunded', 'skipped');
    }

    public function activateIfCompleted(Order $order): void
    {
        if (! $order->link_guarantee || $order->link_guarantee_status !== self::STATUS_PENDING) {
            return;
        }

        if ((string) $order->status !== 'completed' && ! $order->completed_at) {
            $order->loadMissing('items');
            $itemDone = $order->items->contains(
                fn ($line) => $line instanceof OrderItem && ($line->completed_at || filled($line->live_url))
            );
            if (! $itemDone) {
                return;
            }
        }

        $start = $order->completed_at ?: now();
        $months = max(1, (int) config('orders.link_guarantee.months', 12));
        $order->update([
            'link_guarantee_status' => self::STATUS_ACTIVE,
            'link_guarantee_starts_at' => $start,
            'link_guarantee_ends_at' => $start->copy()->addMonths($months),
        ]);
    }

    public function refundCoveredAmount(Order $order, OrderItem $item, string $reason): bool
    {
        if ($order->link_guarantee_status === self::STATUS_REFUNDED || $order->link_guarantee_refunded_at) {
            return false;
        }

        $amount = round((float) ($order->link_guarantee_covered_amount ?: $item->price), 2);
        $guaranteeFee = round((float) ($order->link_guarantee_fee ?? 0), 2);
        if ($amount <= 0) {
            return false;
        }

        try {
            DB::transaction(function () use ($order, $item, $amount, $guaranteeFee, $reason) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || $locked->link_guarantee_status === self::STATUS_REFUNDED) {
                    return;
                }

                $completed = (string) $locked->status === 'completed' || $item->completed_at;
                if ($completed) {
                    $this->clawbackCompletedPlacement($locked, $item, $amount, $reason);
                } else {
                    $this->refunds->refundToAdvertiser($locked, $amount, $reason);
                }

                $locked->update([
                    'link_guarantee_status' => self::STATUS_REFUNDED,
                    'link_guarantee_refunded_at' => now(),
                    'payment_status' => 'refunded',
                ]);

                $this->notifications->notifyRefundCredited(
                    $locked,
                    $amount,
                    '12-month link guarantee: the live URL no longer contained the agreed link.'
                );

                ActivityLogger::tryLog(
                    'link_guarantee.refunded',
                    'Link guarantee refunded €'.number_format($amount, 2).' for order #'.($locked->order_number ?? $locked->id),
                    $locked,
                    [
                        'covered_amount' => $amount,
                        'guarantee_fee' => $guaranteeFee,
                        'reason' => $reason,
                    ],
                    'Order #'.($locked->order_number ?? $locked->id)
                );

                $order->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (\Throwable $e) {
            Log::error('Link guarantee refund failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $order->link_guarantee_status === self::STATUS_REFUNDED;
    }

    private function clawbackCompletedPlacement(Order $order, OrderItem $item, float $amount, string $reason): void
    {
        $this->refunds->refundPaypalCaptureIfPossible($order, $amount, true, 'link-guarantee-'.$item->id);

        $site = Site::query()->find($item->site_id);
        $publisherId = $site?->publisher_id;
        $publisherRoleId = Wallet::publisherRoleId();
        $credit = method_exists($item, 'cappedPublisherCredit')
            ? $item->cappedPublisherCredit()
            : ['amount' => (float) ($item->publisher_amount ?? 0), 'currency' => 'EUR'];
        $targetPayout = round((float) ($credit['amount'] ?? 0), 2);
        if (($item->content_source ?? '') === WritingFeeService::SOURCE_SLB) {
            $slbWritingOnPublisher = round((float) ($item->listing_writing_fee ?? 0), 2);
            $targetPayout = round(max(0, $targetPayout - $slbWritingOnPublisher), 2);
        }

        if ($publisherId && $publisherRoleId && $targetPayout > 0) {
            $publisherWallet = Wallet::lockOrCreateForRole((int) $publisherId, (int) $publisherRoleId, $credit['currency'] ?? 'EUR');
            $available = $publisherWallet->withdrawableBalance();
            $debited = round(min($available, $targetPayout), 2);
            $debtCreated = round(max(0, $targetPayout - $debited), 2);
            if ($debited > 0) {
                $publisherWallet->deductWithdrawable($debited);
                $this->ledger->recordTransferOut(
                    $publisherWallet,
                    $debited,
                    $item,
                    'GUARANTEE-CLAWBACK-'.$item->id,
                    'Link guarantee clawback for order #'.($order->order_number ?? $order->id),
                    [
                        'order_id' => $order->id,
                        'reason' => $reason,
                        'debt_created' => $debtCreated,
                    ]
                );
            }
            if ($debtCreated > 0) {
                $publisherWallet->increaseDebt($debtCreated);
            }
        }

        // Completed wallet holds are already consumed, so refundToAdvertiser
        // (reserved-only) would no-op. Credit the advertiser the same way
        // OrderClawbackService does after a publisher clawback.
        if (($order->payment_method ?? '') === 'paypal') {
            return;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if ($advertiserRoleId && $amount > 0) {
            $advertiserWallet = Wallet::lockOrCreateForRole((int) $order->user_id, (int) $advertiserRoleId);
            $advertiserWallet->credit($amount);
            $this->ledger->recordRefund(
                $advertiserWallet,
                $amount,
                0.0,
                $order,
                $order->reference_code ?: 'GUARANTEE-REFUND-'.$item->id
            );
        }
    }

    private function notifyPublisherToRestore(Order $order, OrderItem $item, $dueAt): void
    {
        $site = $item->relationLoaded('site') ? $item->site : Site::query()->find($item->site_id);
        $publisher = $site?->publisher_id ? User::query()->find($site->publisher_id) : null;
        if (! $publisher) {
            return;
        }

        $dueLabel = $dueAt->timezone(config('app.timezone'))->format('j M Y');
        $this->notifications->notify(
            $publisher,
            InAppNotificationService::TYPE_ORDER_UPDATED,
            'Restore the live link for order #'.$order->order_number,
            'Our monthly guarantee check did not find the advertiser’s URL on the live page. You have until '.$dueLabel.' to restore the hyperlink. If it is still missing we refund the advertiser.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'icon' => 'link',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Open task',
                'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'restore_due_at' => $dueAt->toIso8601String(),
                ],
            ]
        );

        if ($publisher->email) {
            try {
                Mail::to($publisher->email)->send(new LinkGuaranteeRestoreMail($publisher, $order, $item, $dueAt));
            } catch (\Throwable $e) {
                Log::warning('Link guarantee restore mail failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
