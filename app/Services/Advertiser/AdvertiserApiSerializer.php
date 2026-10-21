<?php

namespace App\Services\Advertiser;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\SiteUrlVisibility;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\WritingFeeService;
use App\Services\Mcp\MarketplaceListing;
use App\Support\SiteDescriptionRules;

class AdvertiserApiSerializer
{
    public function __construct(
        private MarketplaceListing $listing,
        private SiteUrlVisibility $visibility,
        private WritingFeeService $writing,
        private CheckoutAddOnService $addons,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function site(User $user, Site $site): ?array
    {
        $row = $this->listing->present($user, $site, detailed: true);
        $prices = $site->catalogPricesForViewer($user);
        $sale = $prices['sale'] !== null ? round((float) $prices['sale'], 2) : null;
        $list = round((float) $prices['list'], 2);
        $writingOffered = $this->writing->writingOffered($site);
        $writer = $this->addons->requestedWriter($site);
        $decorated = $this->addons->checkoutViewItems([['id' => $site->id]])[0] ?? [];
        $writingFee = (float) ($decorated['writing_fee'] ?? 0);
        $guaranteeOffered = $this->addons->siteQualifiesForGuarantee($site);

        return [
            'id' => (int) $site->id,
            'name' => $row['name'],
            'host' => $row['host'],
            'price' => [
                'currency' => 'EUR',
                'list' => $list,
                'sale' => $sale,
            ],
            'price_eur' => $sale ?? $list,
            'da' => (int) ($site->da ?? 0),
            'dr' => (int) ($site->dr ?? 0),
            'traffic' => (int) ($site->traffic ?? 0),
            'country' => (string) ($site->country ?? ''),
            'language' => (string) ($site->language ?? ''),
            'niches' => $row['niches'] ?? [],
            'link_type' => strtolower((string) ($site->link_type ?? 'dofollow')) ?: 'dofollow',
            'dofollow' => ! empty($row['dofollow']),
            'sponsored_label' => $row['sponsored_label'] ?? $site->tagLabel(),
            'turnaround' => (string) ($site->publication_time ?? $site->turnaround_time ?? ''),
            'publication_time' => (string) ($site->publication_time ?? ''),
            'publication_duration' => $site->publicationDurationLabel() ?? '',
            'writing' => [
                'offered' => true,
                'publisher_offered' => $writingOffered,
                'slb_offered' => true,
                'writer' => $writer,
                'fee_eur' => round($writingFee, 2),
                'currency' => 'EUR',
            ],
            'link_guarantee' => [
                'offered' => $guaranteeOffered,
                'percent' => $this->addons->guaranteePercent(),
                'months' => (int) config('orders.link_guarantee.months', 12),
                'note' => $guaranteeOffered
                    ? 'Optional 12-month link guarantee from SEOLinkBuildings.'
                    : 'Not offered: publication period must be at least 12 months or permanent.',
            ],
            'rules' => [
                'description' => SiteDescriptionRules::forTextarea((string) ($site->description ?? '')),
                'link_type' => strtolower((string) ($site->link_type ?? 'dofollow')) ?: 'dofollow',
                'sponsored' => (bool) ($site->sponsored ?? false),
                'as_you_prefer' => (bool) ($site->as_you_prefer ?? false),
                'turnaround' => (string) ($site->publication_time ?? ''),
            ],
            'description' => $row['description'] ?? '',
            'example_url' => $row['example_url'] ?? null,
            'catalog_url' => $row['catalog_url'] ?? url('/advertiser/catalog?site='.$site->id),
        ];
    }

    /**
     * Buyer-safe order payload. Never includes partner rates, markup, or publisher identity.
     *
     * @return array<string, mixed>
     */
    public function order(User $user, Order $order): array
    {
        $order->loadMissing('items');
        $item = $order->items->first();
        $sandbox = AdvertiserSandbox::isSandboxOrder($order);

        return [
            'id' => (int) $order->id,
            'order_number' => (string) ($order->order_number ?? $order->id),
            'status' => (string) ($order->status ?? ''),
            'payment_status' => (string) ($order->payment_status ?? ''),
            'currency' => 'EUR',
            'total' => round((float) ($order->total_amount ?? 0), 2),
            'livemode' => ! $sandbox,
            'sandbox' => $sandbox,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'paid_at' => optional($order->paid_at)?->toIso8601String(),
            'cancellable' => $this->cancellable($order),
            'site' => $this->siteSummary($user, $item),
            'anchor' => (string) ($item?->anchor_text ?? ''),
            'target_url' => (string) ($item?->target_url ?? ''),
            'writing' => $item !== null && in_array((string) ($item->content_source ?? ''), [
                WritingFeeService::SOURCE_PUBLISHER,
                WritingFeeService::SOURCE_SLB,
            ], true),
            'live_url' => $this->liveUrl($item),
            'link_check' => $this->linkCheck($item),
            'publisher_status' => (string) ($item?->publisher_status ?? ''),
        ];
    }

    public function cancellable(Order $order): bool
    {
        if (in_array((string) $order->status, ['cancelled', 'completed'], true)) {
            return false;
        }
        if ((string) $order->payment_status === 'refunded') {
            return false;
        }
        $order->loadMissing('items');
        $item = $order->items->first();
        if ($item && in_array((string) ($item->publisher_status ?? ''), ['accepted', 'completed', 'rejected'], true)) {
            return false;
        }
        if ($item && $item->accepted_at) {
            return false;
        }
        if (($order->publication_mode ?? '') === 'scheduled') {
            return blank($order->schedule_released_at)
                && $order->scheduled_publish_at
                && $order->scheduled_publish_at->isFuture();
        }

        return (string) ($item?->publisher_status ?? 'pending') === 'pending'
            || (string) $order->status === 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    private function siteSummary(User $user, ?OrderItem $item): array
    {
        if (! $item) {
            return ['id' => null, 'name' => '', 'host' => ''];
        }
        $site = $item->relationLoaded('site') ? $item->site : $item->site()->first();
        $name = $site instanceof Site
            ? $this->visibility->nameFor($user, $site)
            : (string) ($item->site_name ?? '');
        $host = $site instanceof Site
            ? $this->visibility->hostFor($user, $site)
            : $this->host((string) ($item->site_url ?? ''));

        return [
            'id' => $item->site_id ? (int) $item->site_id : null,
            'name' => $name,
            'host' => $host,
        ];
    }

    private function liveUrl(?OrderItem $item): ?string
    {
        if (! $item) {
            return null;
        }
        $url = trim((string) ($item->live_url ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkCheck(?OrderItem $item): array
    {
        if (! $item) {
            return [
                'ok' => null,
                'href_found' => null,
                'rel' => null,
                'anchor_match' => null,
                'http_status' => null,
                'checked_at' => null,
            ];
        }

        return [
            'ok' => $item->live_url_check_ok,
            'href_found' => $item->live_url_href_found,
            'rel' => $item->live_url_rel,
            'anchor_match' => $item->live_url_anchor_match,
            'http_status' => $item->live_url_http_status,
            'checked_at' => optional($item->live_url_checked_at)?->toIso8601String(),
        ];
    }

    private function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' ? $host : $url;
    }
}
