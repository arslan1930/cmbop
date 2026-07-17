<?php

namespace App\Services;

use App\Models\Site;

class CartPricingService
{
    /**
     * Advertiser-facing markup. The extra portion is the platform fee.
     */
    public const PLATFORM_MARKUP_RATE = 1.15;

    /**
     * Resolve advertiser unit pricing from the live site listing.
     * Never trust client-supplied price / additional_price values.
     *
     * @return array{
     *   base: float,
     *   additional: float,
     *   total: float,
     *   sensitive_type: ?string,
     *   list_total: float,
     *   discount_percent: float,
     *   discount_amount: float,
     *   discount_labels: array<int, string>
     * }
     */
    public function priceForAdvertiser(Site $site, ?string $sensitiveType = null, int $quantity = 1): array
    {
        $base = round((float) $site->price * self::PLATFORM_MARKUP_RATE, 2);
        $additional = $this->resolveSensitiveAdditional($site, $sensitiveType);
        $listTotal = round($base + $additional, 2);

        $discountPercent = 0.0;
        $labels = [];

        $custom = $site->activeCustomDiscountPercent();
        if ($custom !== null) {
            $discountPercent = max($discountPercent, (float) $custom);
            $labels[] = 'Site offer −'.rtrim(rtrim(number_format($custom, 2), '0'), '.').'%';
        }

        $bulkPercent = $this->bulkDiscountPercentForQuantity($site, $quantity);
        if ($bulkPercent !== null) {
            // Stack: take the better of custom vs bulk (not both) for clarity.
            if ($bulkPercent > $discountPercent) {
                $discountPercent = $bulkPercent;
                $labels = ['Bulk deal −'.rtrim(rtrim(number_format($bulkPercent, 2), '0'), '.').'% on '.$quantity.' articles'];
            } elseif ($bulkPercent == $discountPercent && $custom === null) {
                $labels[] = 'Bulk deal −'.rtrim(rtrim(number_format($bulkPercent, 2), '0'), '.').'%';
            } elseif ($bulkPercent > 0 && $custom !== null && $bulkPercent <= $discountPercent) {
                // custom already winning; keep custom label
            }
        }

        $discountAmount = round($listTotal * ($discountPercent / 100), 2);
        $total = max(0, round($listTotal - $discountAmount, 2));

        return [
            'base' => $base,
            'additional' => $additional,
            'list_total' => $listTotal,
            'total' => $total,
            'sensitive_type' => $additional > 0 ? $sensitiveType : null,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'discount_labels' => $labels,
        ];
    }

    public function bulkDiscountPercentForQuantity(Site $site, int $quantity): ?float
    {
        if (! $site->joinsBulkDiscount()) {
            return null;
        }

        $min = (int) config('site_promotions.bulk.min_qty', 3);
        $max = (int) config('site_promotions.bulk.max_qty', 5);
        if ($quantity < $min || $quantity > $max) {
            return null;
        }

        return (float) $site->bulk_discount_percent;
    }

    /**
     * Look up the sensitive add-on from the site's configured prices.
     *
     * @throws \InvalidArgumentException when the type is not offered by the site
     */
    public function resolveSensitiveAdditional(Site $site, ?string $sensitiveType): float
    {
        if ($sensitiveType === null || $sensitiveType === '') {
            return 0.0;
        }

        $prices = $site->sensitive_prices ?? [];
        if (is_string($prices)) {
            $prices = json_decode($prices, true) ?: [];
        }

        if (! is_array($prices) || ! array_key_exists($sensitiveType, $prices)) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: '.$site->site_name
            );
        }

        return round((float) $prices[$sensitiveType], 2);
    }

    /**
     * Expand a session cart into per-unit line items with server-calculated prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<int, array<string, mixed>>
     *
     * @throws \Exception
     */
    public function expandCart(array $cart): array
    {
        $expanded = [];

        foreach ($cart as $item) {
            $siteId = $item['id'] ?? null;
            $site = Site::where('id', $siteId)->where('active', 1)->first();

            if (! $site) {
                throw new \Exception(
                    'Site not found or inactive: '.($item['name'] ?? $siteId ?? 'unknown')
                );
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $pricing = $this->priceForAdvertiser($site, $item['sensitive_type'] ?? null, $quantity);

            for ($i = 0; $i < $quantity; $i++) {
                $expanded[] = [
                    'site' => $site,
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => $pricing['total'],
                    'base_price' => $pricing['base'],
                    'additional_price' => $pricing['additional'],
                    'sensitive_type' => $pricing['sensitive_type'],
                    'copy_number' => $i + 1,
                    'list_total' => $pricing['list_total'],
                    'discount_percent' => $pricing['discount_percent'],
                    'discount_amount' => $pricing['discount_amount'],
                    'discount_labels' => $pricing['discount_labels'],
                ];
            }
        }

        return $expanded;
    }

    /**
     * Build checkout display rows from the session cart using DB prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{items: array<int, array<string, mixed>>, total: float, savings: float}
     */
    public function buildCheckoutItems(array $cart): array
    {
        $items = [];
        $total = 0.0;
        $savings = 0.0;

        foreach ($cart as $item) {
            $site = Site::where('id', $item['id'] ?? null)->where('active', 1)->first();
            if (! $site) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $pricing = $this->priceForAdvertiser($site, $item['sensitive_type'] ?? null, $quantity);
            $lineTotal = round($pricing['total'] * $quantity, 2);
            $lineList = round($pricing['list_total'] * $quantity, 2);
            $lineSave = round(max(0, $lineList - $lineTotal), 2);
            $total += $lineTotal;
            $savings += $lineSave;

            $items[] = [
                'id' => $site->id,
                'name' => $site->site_name,
                'url' => $site->site_url,
                'price' => $pricing['total'],
                'base_price' => $pricing['base'],
                'additional_price' => $pricing['additional'],
                'sensitive_type' => $pricing['sensitive_type'],
                'quantity' => $quantity,
                'total' => $lineTotal,
                'list_total' => $pricing['list_total'],
                'line_list_total' => $lineList,
                'discount_percent' => $pricing['discount_percent'],
                'discount_amount' => $pricing['discount_amount'],
                'line_savings' => $lineSave,
                'discount_labels' => $pricing['discount_labels'],
                'country' => $site->country,
                'countries' => $site->countryCodes(),
                'language' => $site->language,
                'languages' => $site->languageCodes(),
                'link_type' => $site->link_type,
                'content_submission_id' => $item['content_submission_id'] ?? null,
                'bulk_eligible' => $site->joinsBulkDiscount(),
                'featured' => $site->isFeatured(),
            ];
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
            'savings' => round($savings, 2),
        ];
    }
}
