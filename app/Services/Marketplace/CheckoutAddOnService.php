<?php

namespace App\Services\Marketplace;

use App\Models\Site;
use App\Support\ListingMoney;
use App\Support\PublicationDuration;
use Illuminate\Http\Request;

/**
 * Checkout-only writing option + optional 12-month link guarantee.
 * Applied after CartPricingService expand so catalog.js / cart configure stay untouched.
 */
class CheckoutAddOnService
{
    public function __construct(private WritingFeeService $writing) {}

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function applyRequestToCart(array $cart, Request $request): array
    {
        $sources = $request->input('content_source', []);
        $briefs = $request->input('writing_brief', []);
        $guarantee = $request->boolean('link_guarantee');

        foreach ($cart as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $site = $id > 0 ? Site::query()->find($id) : null;
            $raw = is_array($sources)
                ? (string) ($sources[$id] ?? $sources[(string) $id] ?? self::sourceFromItem($item))
                : self::sourceFromItem($item);
            $source = $this->normalizeRequestedSource($raw, $site instanceof Site ? $site : null);

            $item['content_source'] = $source;
            $item['link_guarantee'] = $guarantee && $this->siteQualifiesForGuarantee($site instanceof Site ? $site : null);
            if (self::isWrittenSource($item) && is_array($briefs)) {
                $brief = $briefs[$id] ?? $briefs[(string) $id] ?? [];
                $item['writing_target_url'] = is_array($brief) ? trim((string) ($brief['target_url'] ?? '')) : '';
                $item['writing_anchor_text'] = is_array($brief) ? trim((string) ($brief['anchor_text'] ?? '')) : '';
            }
            $cart[$i] = $item;
        }

        return array_values($cart);
    }

    /**
     * Publisher self-write when they offer it; otherwise SEOLinkBuildings writes.
     */
    public function requestedWriter(?Site $site): string
    {
        if ($site instanceof Site && $this->writing->writingOffered($site)) {
            return WritingFeeService::SOURCE_PUBLISHER;
        }

        return WritingFeeService::SOURCE_SLB;
    }

    public function siteQualifiesForGuarantee(?Site $site): bool
    {
        if (! $site instanceof Site) {
            return false;
        }

        return PublicationDuration::qualifiesForLinkGuarantee($site->publication_time);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function isPublisherWritten(array $item): bool
    {
        return ($item['content_source'] ?? '') === WritingFeeService::SOURCE_PUBLISHER;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function isWrittenSource(array $item): bool
    {
        return in_array((string) ($item['content_source'] ?? ''), [
            WritingFeeService::SOURCE_PUBLISHER,
            WritingFeeService::SOURCE_SLB,
            WritingFeeService::SOURCE_WRITTEN,
        ], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $expanded
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function decorateExpandedOrders(array $expanded, array $cart, Request $request): array
    {
        $guarantee = $request->boolean('link_guarantee');

        return array_map(function (array $orderItem) use ($cart, $guarantee, $request) {
            $site = $orderItem['site'] ?? null;
            $siteId = (int) ($orderItem['id'] ?? ($site instanceof Site ? $site->id : 0));
            $cartLine = $this->matchingCartLine($cart, $siteId, $orderItem);

            return $this->decorateOrderItem($orderItem, $cartLine, $guarantee, $request);
        }, $expanded);
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @param  array<string, mixed>|null  $cartLine
     * @return array<string, mixed>
     */
    public function decorateOrderItem(array $orderItem, ?array $cartLine, bool $linkGuarantee, ?Request $request = null): array
    {
        $site = $orderItem['site'] ?? null;
        $raw = $cartLine['content_source']
            ?? $orderItem['content_source']
            ?? WritingFeeService::SOURCE_BUYER;
        $source = $this->normalizeRequestedSource((string) $raw, $site instanceof Site ? $site : null);

        $orderItem['content_source'] = WritingFeeService::SOURCE_BUYER;
        $orderItem['writing_fee'] = 0.0;
        $orderItem['listing_writing_fee'] = 0.0;
        $orderItem['writing_target_url'] = trim((string) ($cartLine['writing_target_url'] ?? $orderItem['writing_target_url'] ?? ''));
        $orderItem['writing_anchor_text'] = trim((string) ($cartLine['writing_anchor_text'] ?? $orderItem['writing_anchor_text'] ?? ''));

        if ($site instanceof Site && self::isWrittenSource(['content_source' => $source])) {
            $fee = $this->advertiserWritingFeeForSource($site, $source);
            $listingFee = $this->writing->resolvedListingWritingFee($site);
            $orderItem['content_source'] = $source;
            $orderItem['writing_fee'] = $fee;
            $orderItem['listing_writing_fee'] = $listingFee;
            $orderItem['price'] = round((float) $orderItem['price'] + $fee, 2);
            $orderItem['publisher_amount'] = round((float) ($orderItem['publisher_amount'] ?? 0) + $listingFee, 2);
            $orderItem['advertiser_eur_amount'] = $orderItem['price'];
        }

        $placement = round((float) $orderItem['price'], 2);
        $orderItem['link_guarantee'] = false;
        $orderItem['link_guarantee_fee'] = 0.0;
        $orderItem['link_guarantee_covered_amount'] = 0.0;
        $orderItem['link_guarantee_offered'] = $this->siteQualifiesForGuarantee($site instanceof Site ? $site : null);

        if ($linkGuarantee && $placement > 0 && $orderItem['link_guarantee_offered']) {
            $percent = max(0, (float) config('orders.link_guarantee.percent', 10));
            $fee = round($placement * ($percent / 100), 2);
            $orderItem['link_guarantee'] = true;
            $orderItem['link_guarantee_fee'] = $fee;
            $orderItem['link_guarantee_covered_amount'] = $placement;
            $orderItem['price'] = round($placement + $fee, 2);
            $orderItem['advertiser_eur_amount'] = $orderItem['price'];
        }

        return $orderItem;
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    public function orderColumns(array $orderItem): array
    {
        if (empty($orderItem['link_guarantee'])) {
            return [
                'link_guarantee' => false,
                'link_guarantee_fee' => 0,
                'link_guarantee_covered_amount' => 0,
                'link_guarantee_status' => null,
            ];
        }

        return [
            'link_guarantee' => true,
            'link_guarantee_fee' => $orderItem['link_guarantee_fee'] ?? 0,
            'link_guarantee_covered_amount' => $orderItem['link_guarantee_covered_amount'] ?? 0,
            'link_guarantee_status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    public function orderItemColumns(array $orderItem): array
    {
        return [
            'content_source' => $orderItem['content_source'] ?? WritingFeeService::SOURCE_BUYER,
            'writing_fee' => $orderItem['writing_fee'] ?? 0,
            'listing_writing_fee' => $orderItem['listing_writing_fee'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cartItems
     * @return array<int, array<string, mixed>>
     */
    public function checkoutViewItems(array $cartItems): array
    {
        return array_map(function (array $item) {
            $site = Site::query()->find($item['id'] ?? 0);
            $publisherOffered = $site instanceof Site && $this->writing->writingOffered($site);
            $writer = $this->requestedWriter($site instanceof Site ? $site : null);
            $fee = $site instanceof Site ? $this->advertiserWritingFeeForSource($site, $writer) : 0.0;
            $item['writing_offered'] = $publisherOffered;
            $item['slb_writing_offered'] = true;
            $item['writing_writer'] = $writer;
            $item['writing_fee'] = $fee;
            $item['writing_fee_label'] = $fee > 0 ? format_money_pay($fee) : '';
            $item['guarantee_offered'] = $this->siteQualifiesForGuarantee($site instanceof Site ? $site : null);

            return $item;
        }, $cartItems);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cartItems
     */
    public function anyLineQualifiesForGuarantee(array $cartItems): bool
    {
        foreach ($cartItems as $item) {
            if (! empty($item['guarantee_offered'])) {
                return true;
            }
        }

        return false;
    }

    public function guaranteePercent(): float
    {
        return max(0, (float) config('orders.link_guarantee.percent', 10));
    }

    public function termsText(): string
    {
        $months = (int) config('orders.link_guarantee.months', 12);
        $days = (int) config('orders.link_guarantee.restore_days', 7);
        $percent = rtrim(rtrim(number_format($this->guaranteePercent(), 2), '0'), '.');

        return 'Optional '.$months.'-month link guarantee from SEOLinkBuildings. Available only on listings whose publication period is at least 12 months or Permanent — not on 6-month listings. Once a month we fetch the live URL and check that your target URL still appears as a hyperlink. If it is missing, we ask for restore within '.$days.' days. If it is not restored, we refund the guaranteed placement amount to your wallet. This is SEOLinkBuildings’ promise, not the publisher’s. The guarantee fee is '.$percent.'% of the placement total and is paid by you — publishers pay nothing. This is not a ranking, traffic, or indexation promise.';
    }

    public function eligibilityHelpText(): string
    {
        return 'The 12-month link guarantee is only offered on sites that stay live for at least 12 months or permanently.';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function sourceFromItem(array $item): string
    {
        $source = (string) ($item['content_source'] ?? '');

        return self::isWrittenSource(['content_source' => $source])
            ? $source
            : WritingFeeService::SOURCE_BUYER;
    }

    private function normalizeRequestedSource(string $raw, ?Site $site): string
    {
        $raw = strtolower(trim($raw));
        if (in_array($raw, [
            WritingFeeService::SOURCE_PUBLISHER,
            WritingFeeService::SOURCE_SLB,
            WritingFeeService::SOURCE_WRITTEN,
        ], true)) {
            return $this->requestedWriter($site);
        }

        return WritingFeeService::SOURCE_BUYER;
    }

    /**
     * PR A keeps today’s fee formula for every written line, including SLB,
     * so a writing-disabled listing is not free if this branch merges alone.
     */
    private function advertiserWritingFeeForSource(Site $site, string $source): float
    {
        if ($source === WritingFeeService::SOURCE_PUBLISHER && $this->writing->writingOffered($site)) {
            return $this->writing->resolvedAdvertiserWritingFee($site);
        }

        $listing = app(ListingMoney::class);
        $listingCurrency = $site->listingCurrency();
        $buyerCurrency = $listing->normalizeWallet($listing->buyerCurrency());
        $withBuffer = $listing->appliesBuffer($listingCurrency, $buyerCurrency);

        return round($listing->toEuros($this->writing->resolvedListingWritingFee($site), $listingCurrency, $withBuffer), 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>|null
     */
    private function matchingCartLine(array $cart, int $siteId, array $orderItem): ?array
    {
        foreach ($cart as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) ($row['id'] ?? 0) !== $siteId) {
                continue;
            }

            return $row;
        }

        return null;
    }
}
