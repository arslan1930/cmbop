<?php

namespace App\Services\Advertiser;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketplace\ArticleWritingService;
use App\Services\Partners\PartnerAlertService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Same publisher fan-out the website wallet checkout uses after commit.
 */
class AdvertiserApiPublisherNotifier
{
    /**
     * @param  list<Order>  $orders
     */
    public function notify(array $orders): void
    {
        $siteOrders = [];
        foreach ($orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                if (! app(ArticleWritingService::class)->publisherCanSee($item)) {
                    continue;
                }
                $siteId = (int) $item->site_id;
                if ($siteId <= 0) {
                    continue;
                }
                if (! isset($siteOrders[$siteId])) {
                    $site = Site::query()->find($siteId);
                    if (! $site) {
                        continue;
                    }
                    $siteOrders[$siteId] = ['site' => $site, 'orders' => []];
                }
                $siteOrders[$siteId]['orders'][] = $order;
            }
        }

        foreach ($siteOrders as $row) {
            $site = $row['site'];
            $publisher = User::query()->find($site->publisher_id);
            if (! $publisher || ! $publisher->email) {
                continue;
            }
            try {
                if (app(PartnerAlertService::class)->sendNewOrderIfPartner($publisher, $site, $row['orders'])) {
                    continue;
                }
                Mail::to($publisher->email)->send(new SiteOwnerOrderNotification($site, $row['orders']));
            } catch (\Throwable $e) {
                Log::warning('Advertiser API publisher notify failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
