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
     * @return array{base: float, additional: float, total: float, sensitive_type: ?string}
     */
    public function priceForAdvertiser(Site $site, ?string $sensitiveType = null): array
    {
        $base = round((float) $site->price * self::PLATFORM_MARKUP_RATE, 2);
        $additional = $this->resolveSensitiveAdditional($site, $sensitiveType);

        return [
            'base' => $base,
            'additional' => $additional,
            'total' => round($base + $additional, 2),
            'sensitive_type' => $additional > 0 ? $sensitiveType : null,
        ];
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

        if (!is_array($prices) || !array_key_exists($sensitiveType, $prices)) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: ' . $site->site_name
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

            if (!$site) {
                throw new \Exception(
                    'Site not found or inactive: ' . ($item['name'] ?? $siteId ?? 'unknown')
                );
            }

            $pricing = $this->priceForAdvertiser($site, $item['sensitive_type'] ?? null);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

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
                ];
            }
        }

        return $expanded;
    }

    /**
     * Build checkout display rows from the session cart using DB prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{items: array<int, array<string, mixed>>, total: float}
     */
    public function buildCheckoutItems(array $cart): array
    {
        $items = [];
        $total = 0.0;

        foreach ($cart as $item) {
            $site = Site::where('id', $item['id'] ?? null)->where('active', 1)->first();
            if (!$site) {
                continue;
            }

            $pricing = $this->priceForAdvertiser($site, $item['sensitive_type'] ?? null);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = round($pricing['total'] * $quantity, 2);
            $total += $lineTotal;

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
                'country' => $site->country,
                'countries' => $site->countryCodes(),
                'language' => $site->language,
                'languages' => $site->languageCodes(),
                'link_type' => $site->link_type,
                'content_submission_id' => $item['content_submission_id'] ?? null,
            ];
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
        ];
    }
}
