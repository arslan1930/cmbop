<?php

namespace App\Services\Partners;

use App\Mail\PartnerCorrectionMail;
use App\Mail\PartnerNewOrderMail;
use App\Mail\PartnerPayoutReleasedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\PartnerDashboard;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class PartnerAlertService
{
    public function sendNewOrderIfPartner(User $publisher, Site $site, array $orders): bool
    {
        if (! $publisher->isSupplyPartner()) {
            return false;
        }
        $item = $this->firstItemForSite($site, $orders);
        if (! $item) {
            return true;
        }
        $this->notifyNewOrder($publisher, $item);

        return true;
    }

    public function sendCorrectionIfPartner(User $publisher, Order $order, string $reason): bool
    {
        if (! $publisher->isSupplyPartner()) {
            return false;
        }
        $item = $order->items->first(fn (OrderItem $row) => (int) $row->site?->publisher_id === (int) $publisher->id)
            ?: $order->items->first();
        if (! $item) {
            return true;
        }
        $this->notifyCorrection($publisher, $item, $reason);

        return true;
    }

    public function notifyNewOrder(User $publisher, OrderItem $item): void
    {
        $partner = Partner::forUser($publisher);
        if (! $partner || ! $partner->wantsNotification('new_order')) {
            return;
        }
        Mail::to($publisher->email)->send(new PartnerNewOrderMail(
            $publisher,
            $item,
            $this->orderUrl($publisher, $item, $partner)
        ));
        try {
            app(PartnerWebhookService::class)->emit($partner, 'order.created', [
                'object' => app(PartnerApiSerializer::class)->order($publisher, $item),
            ]);
        } catch (\Throwable) {
            // Webhook outbox is best-effort next to email.
        }
    }

    public function notifyCorrection(User $publisher, OrderItem $item, string $reason): void
    {
        $partner = Partner::forUser($publisher);
        if (! $partner || ! $partner->wantsNotification('correction')) {
            return;
        }
        Mail::to($publisher->email)->send(new PartnerCorrectionMail(
            $publisher,
            $item,
            $reason,
            $this->orderUrl($publisher, $item, $partner)
        ));
    }

    public function notifyPayoutReleased(Withdrawal $withdrawal): void
    {
        $user = $withdrawal->user ?: User::find($withdrawal->user_id);
        if (! $user || ! $user->isSupplyPartner()) {
            return;
        }
        Mail::to($user->email)->send(new PartnerPayoutReleasedMail($user, $withdrawal));
    }

    private function orderUrl(User $publisher, OrderItem $item, Partner $partner): string
    {
        if ($partner->oneClickLinksEnabled() && Schema::hasTable('partner_email_action_tokens')) {
            $token = app(PartnerEmailActionService::class)->issue(
                $publisher,
                (int) $item->id,
                ['view', 'accept', 'reject', 'complete']
            );

            return PartnerDashboard::origin().'/a/'.$token;
        }

        return PartnerDashboard::origin().'/orders/'.$item->id;
    }

    /**
     * @param  list<Order>  $orders
     */
    private function firstItemForSite(Site $site, array $orders): ?OrderItem
    {
        foreach ($orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                if ((int) $item->site_id === (int) $site->id) {
                    return $item;
                }
            }
        }

        return null;
    }
}
