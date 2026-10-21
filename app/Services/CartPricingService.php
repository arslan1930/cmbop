<?php

namespace App\Services;

use App\Models\Site;
use App\Models\User;
use App\Services\Marketplace\WritingFeeService;
use App\Support\ListingMoney;

class CartPricingService
{
    /**
     * @deprecated Use PlatformFeeService tiered fees. Kept for legacy call sites / tests.
     */
    public const PLATFORM_MARKUP_RATE = 1.15;

    private readonly PlatformFeeService $fees;

    public function __construct(?PlatformFeeService $fees = null)
    {
        $this->fees = $fees ?? new PlatformFeeService;
    }

    /**
     * Resolve advertiser unit pricing from the live site listing.
     * Never trust client-supplied price / additional_price / homepage_price values.
     *
     * Discounts are exclusive better-of (never additive): the stronger of an
     * active custom sale and a bulk pack rate (qty 3–5) applies. The cut is
     * absorbed by the platform fee and floored at publisher payout.
     *
     * Homepage placement fees are NEVER discounted — they are added at full
     * publisher price after the article (+ sensitive) discount math.
     *
     * `discount_percent` is the effective rate after that floor (what the
     * advertiser actually saves). `discount_percent_nominal` is the configured
     * better-of % before flooring — use that only when re-applying the offer
     * math (catalog JS), not on chips / checkout labels.
     *
     * @param  int|string|null  $homepageDays  null/'' with $defaultHomepage → longest free; 0/'none' → none; int → that duration
     * @return array{
     *   base: float,
     *   additional: float,
     *   total: float,
     *   article_total: float,
     *   sensitive_type: ?string,
     *   list_total: float,
     *   discount_percent: float,
     *   discount_percent_nominal: float,
     *   discount_amount: float,
     *   discount_labels: array<int, string>,
     *   publisher_price: float,
     *   platform_fee_percent: float,
     *   platform_fee_amount: float,
     *   homepage_days: ?int,
     *   homepage_price: float,
     *   social_channels: list<string>,
     *   publisher_amount: float,
     *   publisher_currency: string,
     *   advertiser_eur_amount: float,
     *   fx_rate: ?float,
     *   fx_buffer_percent: float
     * }
     */
    public function priceForAdvertiser(
        Site $site,
        ?string $sensitiveType = null,
        int $quantity = 1,
        int|string|null $homepageDays = null,
        bool $defaultHomepage = true,
        ?string $buyerCurrency = null,
    ): array {
        $listing = app(ListingMoney::class);
        $listingCurrency = $site->listingCurrency();
        $listingBase = $site->listingBaseAmount();
        $buyerCurrency = $listing->normalizeWallet($buyerCurrency ?? $listing->buyerCurrency());
        $withBuffer = $listing->appliesBuffer($listingCurrency, $buyerCurrency);
        $publisherPrice = $listing->toEuros($listingBase, $listingCurrency, false);
        $advertiserListingEuros = $listing->toEuros($listingBase, $listingCurrency, $withBuffer);
        $listingAdditional = $this->resolveListingSensitiveAdditional($site, $sensitiveType);
        $additional = $listing->toEuros($listingAdditional, $listingCurrency, $withBuffer);
        if ($site->usesPartnerRetailPrice()) {
            $publisherPrice = $site->publisherBasePrice();
            $base = round((float) $site->slb_price_publish_eur, 2);
            $advertiserListingEuros = $base;
            if ($sensitiveType && $site->slb_price_sensitive_extra_eur !== null && (float) $site->slb_price_sensitive_extra_eur > 0) {
                $additional = round((float) $site->slb_price_sensitive_extra_eur, 2);
            }
            $feeAmount = max(0, round($base - $publisherPrice, 2));
            $feePercent = $base > 0 ? round(($feeAmount / $base) * 100, 2) : 0;
        } else {
            $feePercent = $this->fees->feePercentForBase($advertiserListingEuros);
            $feeAmount = $this->fees->feeAmountForBase($advertiserListingEuros);
            $base = $this->fees->advertiserBase($advertiserListingEuros);
        }

        // Canonicalize type to the key stored on the site (e.g. CBD not cbd).
        $canonicalType = null;
        if ($sensitiveType !== null && $sensitiveType !== '' && $additional > 0) {
            $prices = $site->sensitive_prices ?? [];
            if (is_string($prices)) {
                $prices = json_decode($prices, true) ?: [];
            }
            if (is_array($prices)) {
                $canonicalType = $this->resolveSensitiveTypeKey($prices, $sensitiveType);
            }
        }

        $listTotal = round($base + $additional, 2);

        $nominalPercent = 0.0;
        $winner = null; // 'custom' | 'bulk' | null

        $custom = $site->activeCustomDiscountPercent();
        if ($custom !== null) {
            $nominalPercent = max($nominalPercent, (float) $custom);
            $winner = 'custom';
        }

        $bulkPercent = $this->bulkDiscountPercentForQuantity($site, $quantity);
        if ($bulkPercent !== null) {
            // Better-of: take the stronger of custom vs bulk (never additive).
            if ($bulkPercent > $nominalPercent) {
                $nominalPercent = $bulkPercent;
                $winner = 'bulk';
            } elseif ($bulkPercent == $nominalPercent && $custom === null) {
                $winner = 'bulk';
            }
            // When bulk ≤ custom, custom already winning — keep custom.
        }

        $discountAmount = round($listTotal * ($nominalPercent / 100), 2);
        $articleTotal = max(0, round($listTotal - $discountAmount, 2));

        // Publisher payout is the entered listing base + sensitive add-on (never
        // cut by advertiser-facing discounts). Compare the EUR equivalent without
        // the FX buffer so the floor is the publisher earning, not the cushion.
        $publisherArticlePayout = round(
            $publisherPrice + $listing->toEuros($listingAdditional, $listingCurrency, false),
            2
        );
        if ($articleTotal < $publisherArticlePayout) {
            $articleTotal = $publisherArticlePayout;
            $discountAmount = max(0, round($listTotal - $articleTotal, 2));
        }

        // Advertiser-facing % must match real savings after the floor (article only).
        $effectivePercent = self::effectiveDiscountPercent($listTotal, $discountAmount);
        $labels = [];
        if ($effectivePercent > 0 && $winner === 'bulk') {
            $labels[] = 'Bulk deal −'.$this->formatDiscountPercent($effectivePercent).'% on '.$quantity.' articles';
        } elseif ($effectivePercent > 0) {
            $labels[] = 'Site offer −'.$this->formatDiscountPercent($effectivePercent).'%';
        }

        // Fee retained on the article portion after discount (may be €0 when discount eats the fee).
        $feeAmount = max(0, round($articleTotal - $publisherArticlePayout, 2));

        $homepage = $this->resolveHomepageSelection($site, $homepageDays, $defaultHomepage);
        $listingHomepage = $this->resolveListingHomepageAmount($site, $homepage['days']);
        $homepageFee = $listing->toEuros($listingHomepage, $listingCurrency, $withBuffer);
        $total = round($articleTotal + $homepageFee, 2);
        $publisherAmount = round($listingBase + $listingAdditional + $listingHomepage, 2);
        $fxRate = $listing->ratePerEur($listingCurrency);
        $fxBuffer = $withBuffer ? $listing->bufferPercent() : 0.0;

        return [
            'base' => $base,
            'additional' => $additional,
            'list_total' => $listTotal,
            'article_total' => $articleTotal,
            'total' => $total,
            'sensitive_type' => $additional > 0 ? ($canonicalType ?: $sensitiveType) : null,
            'discount_percent' => $effectivePercent,
            'discount_percent_nominal' => $nominalPercent,
            'discount_amount' => $discountAmount,
            'discount_labels' => $labels,
            'publisher_price' => $publisherPrice,
            'platform_fee_percent' => $feePercent,
            'platform_fee_amount' => $feeAmount,
            'homepage_days' => $homepage['days'],
            'homepage_price' => $homepageFee,
            'social_channels' => $site->enabledSocialChannels(),
            'publisher_amount' => $publisherAmount,
            'publisher_currency' => $listingCurrency,
            'advertiser_eur_amount' => $total,
            'fx_rate' => $fxRate,
            'fx_buffer_percent' => $fxBuffer,
        ];
    }

    /**
     * Resolve homepage placement days + full (undiscounted) fee.
     *
     * @return array{days: ?int, price: float}
     *
     * @throws \InvalidArgumentException when an explicit duration is not offered
     */
    public function resolveHomepageSelection(Site $site, int|string|null $requested, bool $useDefaultWhenMissing = true): array
    {
        $options = $site->homepagePlacementOptions();
        if ($options === []) {
            return ['days' => null, 'price' => 0.0];
        }

        $missing = $requested === null || $requested === '';
        if ($missing && $useDefaultWhenMissing) {
            $free = $site->longestFreeHomepageDays();

            return $free === null
                ? ['days' => null, 'price' => 0.0]
                : ['days' => $free, 'price' => 0.0];
        }

        if ($missing || $requested === 'none' || $requested === '0' || $requested === 0) {
            return ['days' => null, 'price' => 0.0];
        }

        $days = (int) $requested;
        if (! array_key_exists($days, $options)) {
            throw new \InvalidArgumentException(
                'Invalid homepage placement option for site: '.$site->site_name
            );
        }

        return ['days' => $days, 'price' => round((float) $options[$days], 2)];
    }

    /**
     * Percent of list total actually saved (after publisher-payout floor).
     */
    public static function effectiveDiscountPercent(float $listTotal, float $discountAmount): float
    {
        if ($listTotal <= 0 || $discountAmount <= 0) {
            return 0.0;
        }

        return round(($discountAmount / $listTotal) * 100, 2);
    }

    private function formatDiscountPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2), '0'), '.');
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

        if (! is_array($prices)) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: '.$site->site_name
            );
        }

        $resolvedKey = $this->resolveSensitiveTypeKey($prices, $sensitiveType);
        if ($resolvedKey === null) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: '.$site->site_name
            );
        }

        return round((float) $prices[$resolvedKey], 2);
    }

    public function resolveListingSensitiveAdditional(Site $site, ?string $sensitiveType): float
    {
        if ($sensitiveType === null || $sensitiveType === '') {
            return 0.0;
        }

        $prices = $site->listingSensitiveMap();
        if ($prices === []) {
            return 0.0;
        }

        $resolvedKey = $this->resolveSensitiveTypeKey($prices, $sensitiveType);
        if ($resolvedKey === null) {
            throw new \InvalidArgumentException(
                'Invalid or unavailable sensitive content type for site: '.$site->site_name
            );
        }

        return round((float) $prices[$resolvedKey], 2);
    }

    public function resolveListingHomepageAmount(Site $site, ?int $days): float
    {
        if ($days === null) {
            return 0.0;
        }

        $map = $site->listingHomepageMap();
        $key = (string) $days;
        if (isset($map[$key]) && is_numeric($map[$key])) {
            return round((float) $map[$key], 2);
        }

        $options = $site->homepagePlacementOptions();

        return isset($options[$days]) ? round((float) $options[$days], 2) : 0.0;
    }

    /**
     * Match a requested sensitive type to the site's configured key (case-insensitive).
     * Publishers store "CBD"; clients may send "cbd".
     *
     * @param  array<string, mixed>  $prices
     */
    public function resolveSensitiveTypeKey(array $prices, string $sensitiveType): ?string
    {
        if (array_key_exists($sensitiveType, $prices)) {
            return $sensitiveType;
        }

        $needle = strtolower(trim($sensitiveType));
        foreach ($prices as $key => $amount) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            if (strtolower((string) $key) === $needle && is_numeric($amount) && (float) $amount > 0) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Keep only cart lines that are still catalog-visible and not owned by the shopper.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{
     *     cart: list<array<string, mixed>>,
     *     removed_inactive: list<string>,
     *     removed_owned: list<string>,
     *     changed: bool
     * }
     */
    public function pruneAdvertiserCart(array $cart, ?User $buyer = null): array
    {
        $cart = array_values(array_filter($cart, 'is_array'));
        if ($cart === []) {
            return ['cart' => [], 'removed_inactive' => [], 'removed_owned' => [], 'changed' => false];
        }

        $siteIds = collect($cart)->pluck('id')->filter()->unique()->values();
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()->catalogVisible()->whereIn('id', $siteIds)->get()->keyBy('id');

        $kept = [];
        $removed = [];
        $removedOwned = [];
        foreach ($cart as $line) {
            $site = $sites->get((int) ($line['id'] ?? 0));
            $name = trim((string) ($site?->site_name ?? $line['name'] ?? ''));
            if ($name === '') {
                $name = 'A website';
            }
            if (! $site || ! $site->isCatalogVisible()) {
                $removed[] = $name;

                continue;
            }
            if ($site->isOwnedBy($buyer)) {
                $removedOwned[] = $name;

                continue;
            }
            $kept[] = $line;
        }

        $removed = array_values(array_unique($removed));
        $removedOwned = array_values(array_unique($removedOwned));

        return [
            'cart' => $kept,
            'removed_inactive' => $removed,
            'removed_owned' => $removedOwned,
            'changed' => count($kept) !== count($cart),
        ];
    }

    /**
     * Drop hidden/owned lines from the session cart.
     *
     * @return array{
     *     cart: list<array<string, mixed>>,
     *     removed_inactive: list<string>,
     *     removed_owned: list<string>,
     *     changed: bool
     * }
     */
    public function syncAdvertiserSessionCart(?User $buyer = null): array
    {
        $memoKey = '_advertiser_cart_sync';
        try {
            if (app()->bound('request')) {
                $memo = request()->attributes->get($memoKey);
                if (is_array($memo) && isset($memo['cart'])) {
                    return $memo;
                }
            }
        } catch (\Throwable) {
        }

        $sessionCart = session('cart', []);
        if (! is_array($sessionCart)) {
            session()->put('cart', []);

            $empty = [
                'cart' => [],
                'removed_inactive' => [],
                'removed_owned' => [],
                'changed' => true,
            ];
            $this->rememberAdvertiserCartSync($memoKey, $empty);

            return $empty;
        }

        $pruned = $this->pruneAdvertiserCart($sessionCart ?: [], $buyer);
        if ($pruned['changed']) {
            session()->put('cart', array_values($pruned['cart']));
        }

        $this->rememberAdvertiserCartSync($memoKey, $pruned);

        return $pruned;
    }

    /**
     * @param  array{cart: list<array<string, mixed>>, removed_inactive: list<string>, removed_owned: list<string>, changed: bool}  $pruned
     */
    private function rememberAdvertiserCartSync(string $key, array $pruned): void
    {
        try {
            if (app()->bound('request')) {
                request()->attributes->set($key, $pruned);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Keep only cart lines that are still catalog-visible.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{cart: array<int, array<string, mixed>>, removed: array<int, array<string, mixed>>}
     */
    public function pruneUnavailableCartItems(array $cart): array
    {
        $pruned = $this->pruneAdvertiserCart($cart, auth()->user());

        return [
            'cart' => $pruned['cart'],
            'removed' => array_map(
                static fn (string $name) => ['name' => $name],
                $pruned['removed_inactive']
            ),
        ];
    }

    /**
     * Buyer display currency for cart / checkout. Prefer the cart owner's
     * saved users.display_currency so totals match catalog for that shopper.
     */
    private function resolveBuyerCurrency(?int $buyerId = null): string
    {
        $listing = app(ListingMoney::class);
        if ($buyerId !== null && $buyerId > 0) {
            $auth = auth()->user();
            if ($auth instanceof User && (int) $auth->id === $buyerId) {
                return $listing->buyerCurrency($auth);
            }
            $loaded = User::query()->find($buyerId);
            if ($loaded instanceof User) {
                return $listing->buyerCurrency($loaded);
            }
        }

        return $listing->buyerCurrency();
    }

    /**
     * Same ownership rule as Site::isOwnedBy() without loading the user.
     */
    private function buyerOwnsSite(Site $site, ?int $buyerId): bool
    {
        if ($buyerId === null || $buyerId <= 0) {
            return false;
        }

        if ((int) $site->publisher_id === $buyerId) {
            return true;
        }

        $ownerId = (int) ($site->getAttribute('owner_id') ?? 0);

        return $ownerId > 0 && $ownerId === $buyerId;
    }

    /**
     * Expand session cart lines into per-copy order rows using live DB prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @param  int|null  $buyerId  Drop listings owned by this user (cannot self-order).
     * @return list<array<string, mixed>>
     */
    public function expandCart(array $cart, ?int $buyerId = null): array
    {
        $expanded = [];
        $unavailable = [];
        $owned = [];

        foreach ($cart as $item) {
            $siteId = $item['id'] ?? null;
            $site = $siteId ? Site::query()->where('id', $siteId)->first() : null;

            if (! $site) {
                $unavailable[] = (string) ($item['name'] ?? $siteId ?? 'unknown');

                continue;
            }

            if ($this->buyerOwnsSite($site, $buyerId)) {
                $owned[] = (string) ($item['name'] ?? $site->site_name);

                continue;
            }

            if (! $site->isCatalogVisible()) {
                $unavailable[] = (string) ($item['name'] ?? $site->site_name);

                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $hasHomepageKey = array_key_exists('homepage_days', $item);
            $pricing = $this->priceForAdvertiser(
                $site,
                $item['sensitive_type'] ?? null,
                $quantity,
                $item['homepage_days'] ?? null,
                ! $hasHomepageKey,
                $this->resolveBuyerCurrency($buyerId)
            );

            for ($i = 0; $i < $quantity; $i++) {
                $expanded[] = [
                    'site' => $site,
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => $pricing['total'],
                    'base_price' => $pricing['base'],
                    'additional_price' => $pricing['additional'],
                    'sensitive_type' => $pricing['sensitive_type'],
                    'homepage_days' => $pricing['homepage_days'],
                    'homepage_price' => $pricing['homepage_price'],
                    'social_channels' => $pricing['social_channels'],
                    'copy_number' => $i + 1,
                    'list_total' => $pricing['list_total'],
                    'article_total' => $pricing['article_total'],
                    'discount_percent' => $pricing['discount_percent'],
                    'discount_amount' => $pricing['discount_amount'],
                    'discount_labels' => $pricing['discount_labels'],
                    'publisher_price' => $pricing['publisher_price'],
                    'platform_fee_percent' => $pricing['platform_fee_percent'],
                    'platform_fee_amount' => $pricing['platform_fee_amount'],
                    'publisher_amount' => $pricing['publisher_amount'] ?? $pricing['publisher_price'],
                    'publisher_currency' => $pricing['publisher_currency'] ?? 'EUR',
                    'advertiser_eur_amount' => $pricing['advertiser_eur_amount'] ?? $pricing['total'],
                    'fx_rate' => $pricing['fx_rate'] ?? null,
                    'fx_buffer_percent' => $pricing['fx_buffer_percent'] ?? 0,
                ];
            }
        }

        if ($expanded === []) {
            if ($owned !== [] && $unavailable === []) {
                throw new \Exception('You cannot order placements on your own websites.');
            }

            if ($unavailable !== []) {
                throw new \Exception(
                    'Site not found or inactive: '.implode(', ', array_unique($unavailable))
                );
            }

            throw new \Exception('Your cart is empty.');
        }

        return $expanded;
    }

    /**
     * Build checkout display rows from the session cart using DB prices.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{items: array<int, array<string, mixed>>, total: float, savings: float}
     */
    public function buildCheckoutItems(array $cart, ?int $buyerId = null): array
    {
        $items = [];
        $total = 0.0;
        $savings = 0.0;

        foreach ($cart as $item) {
            $site = Site::query()->catalogVisible()->where('id', $item['id'] ?? null)->first();
            if (! $site) {
                continue;
            }
            if ($this->buyerOwnsSite($site, $buyerId)) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $hasHomepageKey = array_key_exists('homepage_days', $item);
            $pricing = $this->priceForAdvertiser(
                $site,
                $item['sensitive_type'] ?? null,
                $quantity,
                $item['homepage_days'] ?? null,
                ! $hasHomepageKey,
                $this->resolveBuyerCurrency($buyerId)
            );
            $unitListWithHomepage = round($pricing['list_total'] + $pricing['homepage_price'], 2);
            $lineTotal = round($pricing['total'] * $quantity, 2);
            $lineList = round($unitListWithHomepage * $quantity, 2);
            $lineSave = round(max(0, ($pricing['list_total'] - $pricing['article_total']) * $quantity), 2);
            $total += $lineTotal;
            $savings += $lineSave;

            // Preserve per-placement article slots (bulk packs qty 3–5). Dropping
            // content_submission_ids left checkout with only a scalar id and lost
            // assignments for copies 2…N.
            $slotIds = $this->normalizeContentSubmissionIds($item, $quantity);

            $items[] = [
                'id' => $site->id,
                'name' => $site->site_name,
                'url' => $site->site_url,
                'price' => $pricing['total'],
                'base_price' => $pricing['base'],
                'additional_price' => $pricing['additional'],
                'sensitive_type' => $pricing['sensitive_type'],
                'homepage_days' => $pricing['homepage_days'],
                'homepage_price' => $pricing['homepage_price'],
                'social_channels' => $pricing['social_channels'],
                'quantity' => $quantity,
                'total' => $lineTotal,
                'list_total' => $pricing['list_total'],
                'line_list_total' => $lineList,
                'discount_percent' => $pricing['discount_percent'],
                'discount_amount' => $pricing['discount_amount'],
                'line_savings' => $lineSave,
                'discount_labels' => $pricing['discount_labels'],
                'publisher_price' => $pricing['publisher_price'],
                'platform_fee_percent' => $pricing['platform_fee_percent'],
                'platform_fee_amount' => $pricing['platform_fee_amount'],
                'country' => $site->country,
                'countries' => $site->countryCodes(),
                'language' => $site->language,
                'languages' => $site->languageCodes(),
                'link_type' => $site->link_type,
                'content_submission_id' => ($slotIds[0] ?? 0) > 0 ? $slotIds[0] : null,
                'content_submission_ids' => $slotIds,
                'bulk_eligible' => $site->joinsBulkDiscount(),
                'featured' => $site->isFeatured(),
                'content_source' => $item['content_source'] ?? WritingFeeService::SOURCE_BUYER,
                'writing_target_url' => trim((string) ($item['writing_target_url'] ?? '')),
                'writing_anchor_text' => trim((string) ($item['writing_anchor_text'] ?? '')),
            ];
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
            'savings' => round($savings, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, int>
     */
    private function normalizeContentSubmissionIds(array $item, int $quantity): array
    {
        $quantity = max(1, $quantity);
        $raw = is_array($item['content_submission_ids'] ?? null)
            ? $item['content_submission_ids']
            : [];
        $legacy = (int) ($item['content_submission_id'] ?? 0);
        $normalized = [];

        for ($i = 0; $i < $quantity; $i++) {
            $id = (int) ($raw[$i] ?? 0);
            if ($id <= 0 && $i === 0 && $legacy > 0) {
                $id = $legacy;
            }
            $normalized[$i] = max(0, $id);
        }

        return $normalized;
    }
}
