<?php

namespace App\Services\Marketplace;

use App\Models\Site;
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
            $source = is_array($sources)
                ? (string) ($sources[$id] ?? $sources[(string) $id] ?? self::sourceFromItem($item))
                : self::sourceFromItem($item);
            $source = $source === WritingFeeService::SOURCE_PUBLISHER
                ? WritingFeeService::SOURCE_PUBLISHER
                : WritingFeeService::SOURCE_BUYER;

            $item['content_source'] = $source;
            $item['link_guarantee'] = $guarantee;
            if ($source === WritingFeeService::SOURCE_PUBLISHER && is_array($briefs)) {
                $brief = $briefs[$id] ?? $briefs[(string) $id] ?? [];
                $item['writing_target_url'] = is_array($brief) ? trim((string) ($brief['target_url'] ?? '')) : '';
                $item['writing_anchor_text'] = is_array($brief) ? trim((string) ($brief['anchor_text'] ?? '')) : '';
            }
            $cart[$i] = $item;
        }

        return array_values($cart);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function isPublisherWritten(array $item): bool
    {
        return ($item['content_source'] ?? '') === WritingFeeService::SOURCE_PUBLISHER;
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
        $source = $cartLine['content_source']
            ?? $orderItem['content_source']
            ?? WritingFeeService::SOURCE_BUYER;

        $orderItem['content_source'] = WritingFeeService::SOURCE_BUYER;
        $orderItem['writing_fee'] = 0.0;
        $orderItem['listing_writing_fee'] = 0.0;
        $orderItem['writing_target_url'] = trim((string) ($cartLine['writing_target_url'] ?? $orderItem['writing_target_url'] ?? ''));
        $orderItem['writing_anchor_text'] = trim((string) ($cartLine['writing_anchor_text'] ?? $orderItem['writing_anchor_text'] ?? ''));

        if ($site instanceof Site
            && $source === WritingFeeService::SOURCE_PUBLISHER
            && $this->writing->writingOffered($site)
        ) {
            $fee = $this->writing->resolvedAdvertiserWritingFee($site);
            $listingFee = $this->writing->resolvedListingWritingFee($site);
            $orderItem['content_source'] = WritingFeeService::SOURCE_PUBLISHER;
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

        if ($linkGuarantee && $placement > 0) {
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
            $offered = $site instanceof Site && $this->writing->writingOffered($site);
            $fee = $offered && $site ? $this->writing->resolvedAdvertiserWritingFee($site) : 0.0;
            $item['writing_offered'] = $offered;
            $item['writing_fee'] = $fee;
            $item['writing_fee_label'] = $fee > 0 ? format_money_pay($fee) : '';

            return $item;
        }, $cartItems);
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

        return 'Optional '.$months.'-month link guarantee. Once a month we fetch the live URL and check that your target URL still appears as a hyperlink. If it is missing, the publisher has '.$days.' days to restore it. If it is not restored, we refund the guaranteed placement amount to your wallet. The guarantee fee is '.$percent.'% of the placement total and is paid by you — publishers pay nothing. This is not a ranking, traffic, or indexation promise.';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function sourceFromItem(array $item): string
    {
        return ($item['content_source'] ?? '') === WritingFeeService::SOURCE_PUBLISHER
            ? WritingFeeService::SOURCE_PUBLISHER
            : WritingFeeService::SOURCE_BUYER;
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
