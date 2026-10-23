    @if(isset($bulkDeals) && $bulkDeals->count())
    @php
        $bulkPageSize = 6;
        $bulkDealPages = $bulkDeals->values()->chunk($bulkPageSize);
        $bulkPageCount = max(1, $bulkDealPages->count());
        $bulkStartOpen = request('bulk_deals') == '1' || request('bulk_deals') === 1;
        $bulkSort = strtolower(trim((string) request('bulk_sort', 'discount')));
        if (! in_array($bulkSort, ['discount', 'price', 'dr'], true)) {
            $bulkSort = 'discount';
        }
        $liveDealPercents = $bulkDeals->map(fn ($deal) => (float) ($deal->bulk_pack_discount_percent ?? 0))
            ->filter(fn ($pct) => $pct > 0);
        $liveDealMinPct = $liveDealPercents->isNotEmpty() ? (float) $liveDealPercents->min() : null;
        $liveDealMaxPct = $liveDealPercents->isNotEmpty() ? (float) $liveDealPercents->max() : null;
        $formatDealPct = static fn (?float $pct) => $pct === null
            ? ''
            : (string) (int) round($pct);
        $bulkCartSiteIds = [];
        try {
            foreach (is_array($cart ?? null) ? $cart : [] as $cartLine) {
                if (is_array($cartLine) && isset($cartLine['id'])) {
                    $cid = (int) $cartLine['id'];
                    if ($cid > 0) {
                        $bulkCartSiteIds[$cid] = $cid;
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $bulkCartSiteIds = [];
        }
    @endphp
    {{-- Paged batches of 6 as a wrapping 2-row grid (3×2 desktop). Numbered
         pager only — no horizontal scrollbar or half-cards. First page is
         server-rendered so the rail does not squash cards before catalog.js
         pages them. --}}
    <section class="card border-0 shadow-sm mb-3 catalog-bulk-section{{ $bulkStartOpen ? '' : ' is-collapsed' }}"
             data-bulk-rail
             data-bulk-page-size="6"
             data-bulk-sort="{{ $bulkSort }}"
             data-bulk-start="{{ $bulkStartOpen ? 'open' : 'collapsed' }}"
             data-bulk-remember="catalog.bulkDeals.collapsed"
             aria-labelledby="bulkDealsHeading">
        <div class="card-header bg-white catalog-bulk-header">
            <div class="min-w-0 catalog-bulk-heading">
                <strong id="bulkDealsHeading">
                    <i class="fa-solid fa-tags me-1 text-success" aria-hidden="true"></i>
                    Bulk discount deals
                    <span class="badge rounded-pill catalog-bulk-count">{{ $bulkDeals->count() }} {{ \Illuminate\Support\Str::plural('deal', $bulkDeals->count()) }}</span>
                </strong>
                <div class="small text-muted catalog-bulk-subhead">
                    @if($liveDealMaxPct !== null)
                        Save up to {{ $formatDealPct($liveDealMaxPct) }}% on 3-article packs
                    @else
                        Save on 3-article packs
                    @endif
                </div>
            </div>

            <div class="catalog-bulk-controls">
                <label class="catalog-bulk-sort" for="bulkDealSort">
                    <span class="catalog-bulk-sort__label">Sort</span>
                    <select id="bulkDealSort"
                            class="form-select form-select-sm catalog-bulk-sort__select"
                            data-bulk-sort
                            aria-label="Sort bulk discount deals">
                        <option value="discount" @selected($bulkSort === 'discount')>Biggest discount</option>
                        <option value="price" @selected($bulkSort === 'price')>Lowest price</option>
                        <option value="dr" @selected($bulkSort === 'dr')>Highest DR</option>
                    </select>
                </label>
                <button type="button"
                        class="catalog-bulk-toggle"
                        data-bulk-toggle
                        aria-expanded="{{ $bulkStartOpen ? 'true' : 'false' }}"
                        aria-controls="bulkDealsBody"
                        title="{{ $bulkStartOpen ? 'Hide deals' : 'Show deals' }}"
                        aria-label="{{ $bulkStartOpen ? 'Hide deals' : 'Show deals' }}">
                    <i class="fa-solid {{ $bulkStartOpen ? 'fa-chevron-up' : 'fa-chevron-down' }}"
                       data-bulk-toggle-icon
                       aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="card-body catalog-bulk-body" id="bulkDealsBody">
            <div class="catalog-bulk-viewport" data-bulk-viewport>
                <div class="catalog-bulk-rail"
                     id="bulkDealsRail"
                     data-bulk-track
                     tabindex="0"
                     role="group"
                     aria-label="Bulk discount deals">
                    @foreach($bulkDealPages as $pageIndex => $pageDeals)
                    <div class="catalog-bulk-page-panel"
                         data-bulk-page-panel
                         role="group"
                         aria-label="Bulk deals page {{ $pageIndex + 1 }}"
                         @if($pageIndex > 0) inert aria-hidden="true" @endif>
                    @php
                        $pageBestSiteId = null;
                        $pageBestSave = -1.0;
                        $pageBestCount = 0;
                        foreach ($pageDeals as $pageDeal) {
                            $pageList = (float) ($pageDeal->bulk_pack_list_total ?? 0);
                            $pageAfter = (float) ($pageDeal->bulk_pack_now_total ?? $pageList);
                            $pageSave = max(0, round($pageList - $pageAfter, 2));
                            if ($pageSave > $pageBestSave) {
                                $pageBestSave = $pageSave;
                                $pageBestCount = 1;
                                $pageBestSiteId = (int) $pageDeal->id;
                            } elseif ($pageSave === $pageBestSave && $pageSave > 0) {
                                $pageBestCount++;
                                $pageBestSiteId = null;
                            }
                        }
                        if ($pageBestCount !== 1 || $pageBestSave <= 0) {
                            $pageBestSiteId = null;
                        }
                    @endphp
                    @foreach($pageDeals as $deal)
                        @php
                            $qtyExample = 3;
                            $list = 0.0;
                            $after = 0.0;
                            $pct = 0.0;
                            $saveAmount = 0.0;
                            $unitAfter = 0.0;
                            $badgeKind = 'bulk';
                            $pctRounded = 0;
                            $pctLabel = null;
                            $dealHost = '';
                            $dealUrl = '';
                            $dealTld = '';
                            $dealName = '';
                            $dealSearch = '';
                            $dealFaviconChain = [];
                            $dealFaviconUrl = null;
                            $dealTraffic = null;
                            $dealCountryCode = null;
                            $dealCountryName = '';
                            $dealLanguageLabel = '';
                            $dealDofollow = false;
                            $dealExpiresAt = null;
                            $dealInCart = in_array((int) $deal->id, $bulkCartSiteIds, true);
                            try {
                                $qtyExample = (int) ($deal->bulk_pack_qty ?? 3);
                                if ($qtyExample < 1) {
                                    $qtyExample = 3;
                                }
                                $list = (float) ($deal->bulk_pack_list_total ?? round(((float) $deal->price) * $qtyExample, 2));
                                $after = (float) ($deal->bulk_pack_now_total ?? $list);
                                $saveAmount = max(0, round($list - $after, 2));
                                $unitAfter = $qtyExample > 0 ? round($after / $qtyExample, 2) : $after;
                                // Better-of % (custom may beat bulk) — never show a bulk badge
                                // that disagrees with the floored “now” total.
                                $pct = (float) ($deal->bulk_pack_discount_percent ?? $deal->bulk_discount_percent ?? 0);
                                $badgeKind = (string) ($deal->bulk_pack_badge_kind ?? 'bulk');
                                $pctRounded = $pct > 0 ? (int) round($pct) : 0;
                                $pctLabel = $pctRounded > 0 ? '−'.$pctRounded.'%' : null;
                                // Bulk deals never follow catalog hide/mask rules —
                                // full name + https URL + TLD stay visible (limited rail).
                                // Rail follows the main Catalog country= filter (Option 1).
                                // "Search deal by site" matches name / URL / host / TLD.
                                $dealHost = $urlVisibility->host($deal->site_url);
                                $dealUrl = $urlVisibility->httpsRootedUrl($deal->site_url);
                                $dealTld = $urlVisibility->tld($deal->site_url);
                                $dealName = (string) $deal->site_name;
                                $dealSearch = mb_strtolower(trim(implode(' ', array_filter([
                                    $dealName,
                                    $dealUrl,
                                    $dealHost,
                                    $dealTld,
                                ]))));
                                if ($deal instanceof \App\Models\Site) {
                                    $dealFaviconUrl = $deal->catalogTileFaviconUrl();
                                    $dealFaviconChain = $dealFaviconUrl ? [$dealFaviconUrl] : [];
                                    $trafficRaw = $deal->traffic;
                                    if ($trafficRaw !== null && (int) $trafficRaw > 0) {
                                        $dealTraffic = (int) $trafficRaw;
                                    }
                                    $dealCountryCode = $deal->primaryCountryCode();
                                    if ($dealCountryCode) {
                                        $dealCountryName = fullCountry($dealCountryCode);
                                    }
                                    $langCode = $deal->primaryLanguageCode();
                                    if ($langCode) {
                                        $dealLanguageLabel = fullLanguage($langCode);
                                    }
                                    $dealDofollow = $deal->linkTypeLabel() === 'DoFollow';
                                    $endsAt = $deal->custom_discount_ends_at;
                                    if ($endsAt instanceof \Carbon\CarbonInterface && $endsAt->isFuture()) {
                                        $dealExpiresAt = $endsAt;
                                    }
                                }
                            } catch (\Throwable $e) {
                                report($e);
                            }
                            $dealLabel = $dealHost !== '' ? $dealHost : $dealName;
                            $dealUrlTitle = $dealUrl !== '' ? $dealUrl : $dealLabel;
                            $viewSearch = $dealHost !== '' ? $dealHost : $dealName;
                        @endphp
                        <article class="bulk-deal-card{{ $pageBestSiteId === (int) $deal->id ? ' is-best-deal' : '' }}"
                                 data-bulk-card
                                 data-bulk-deal-card
                                 data-bulk-site-id="{{ $deal->id }}"
                                 data-bulk-host="{{ $dealHost }}"
                                 data-bulk-search-text="{{ $dealSearch }}"
                                 data-bulk-discount="{{ $pct }}"
                                 data-bulk-price="{{ $after }}"
                                 data-bulk-save="{{ $saveAmount }}"
                                 data-bulk-dr="{{ (int) ($deal->dr ?? 0) }}">
                            <div class="bulk-deal-card__head">
                                @include('advertiser.partials.catalog-site-tile', [
                                    'label' => $dealHost !== '' ? $dealHost : $dealName,
                                    'alt' => $dealName !== '' ? $dealName : $dealHost,
                                    'size' => 'md',
                                    'faviconUrl' => $dealFaviconUrl,
                                    'faviconChain' => $dealFaviconChain,
                                    'initialsFallback' => true,
                                ])
                                <div class="bulk-deal-card__identity min-w-0 flex-grow-1">
                                    <a href="{{ route('advertiser.catalog', array_filter(['search' => $viewSearch])) }}"
                                       class="bulk-deal-card__name"
                                       data-bulk-view-site="{{ $deal->id }}"
                                       data-bulk-view-search="{{ $viewSearch }}"
                                       data-no-tip
                                       title="{{ $dealUrlTitle }}">{{ $dealLabel }}</a>
                                    @if($pageBestSiteId === (int) $deal->id)
                                        <span class="bulk-deal-card__best">Best deal</span>
                                    @endif
                                </div>
                            </div>

                            <div class="bulk-deal-card__metrics">
                                <span class="bulk-deal-card__chip"><span class="bulk-deal-card__chip-label">DR</span> <strong>{{ $deal->dr }}</strong></span>
                                <span class="bulk-deal-card__chip"><span class="bulk-deal-card__chip-label">DA</span> <strong>{{ $deal->da }}</strong></span>
                                @if($dealTraffic !== null)
                                    <span class="bulk-deal-card__chip" title="{{ number_format($dealTraffic) }} monthly organic visits"><span class="bulk-deal-card__chip-label">Traffic</span> <strong>{{ $deal->formattedTraffic() }}</strong></span>
                                @endif
                                @if($dealCountryCode)
                                    <span class="bulk-deal-card__chip bulk-deal-card__market" title="{{ $dealCountryName !== '' ? $dealCountryName : strtoupper($dealCountryCode) }}">
                                        @php $dealFlag = getCountryFlag($dealCountryCode); @endphp
                                        @if($dealFlag !== '')
                                            <span class="bulk-deal-card__flag" aria-hidden="true">{!! $dealFlag !!}</span>
                                        @else
                                            <span class="bulk-deal-card__flag-code">{{ strtoupper($dealCountryCode) }}</span>
                                        @endif
                                        @if($dealLanguageLabel !== '')
                                            <span>{{ $dealLanguageLabel }}</span>
                                        @endif
                                    </span>
                                @elseif($dealLanguageLabel !== '')
                                    <span class="bulk-deal-card__chip">{{ $dealLanguageLabel }}</span>
                                @endif
                                @if($dealDofollow)
                                    <span class="bulk-deal-card__dofollow">DoFollow</span>
                                @endif
                            </div>

                            <div class="bulk-deal-card__price">
                                <span class="bulk-deal-card__was">{{ format_money($list) }}</span>
                                <strong class="bulk-deal-card__now">{{ format_money($after) }}</strong>
                                @if($saveAmount > 0 || $pctLabel)
                                    <span class="bulk-deal-card__pct"
                                          data-no-tip
                                          title="{{ $badgeKind === 'sale'
                                              ? 'Site sale applies on this pack (better than the bulk rate)'
                                              : 'Bulk discount on '.$qtyExample.'–'.(int) config('site_promotions.bulk.max_qty', 5).' articles' }}">
                                        @if($saveAmount > 0)
                                            <span class="bulk-deal-card__pct-save">Save {{ format_money($saveAmount) }}</span>
                                        @endif
                                        @if($pctLabel)
                                            <span class="bulk-deal-card__pct-rate">{{ $badgeKind === 'sale' ? 'Sale ' : '' }}{{ $pctLabel }}</span>
                                        @endif
                                    </span>
                                @endif
                                <span class="bulk-deal-card__qty">{{ $qtyExample }} articles · <span class="bulk-deal-card__each">{{ format_money($unitAfter) }} each</span></span>
                            </div>

                            <p class="bulk-deal-card__expiry mb-0">@if($dealExpiresAt)Ends {{ $dealExpiresAt->timezone(config('app.timezone'))->format('M j, Y') }}@else&nbsp;@endif</p>

                            <button type="button" class="btn btn-sm btn-outline-primary buy-now bulk-deal-card__cta{{ $dealInCart ? ' is-in-cart' : '' }}"
                                    data-id="{{ $deal->id }}"
                                    data-base-price="{{ $deal->price }}"
                                    data-publisher-price="{{ $deal->original_price ?? $deal->price }}"
                                    data-name="{{ $dealName }}"
                                    data-bulk-hint="1"
                                    data-bulk-qty="{{ $qtyExample }}"
                                    @if($dealInCart) data-in-cart="1" @endif
                                    aria-label="{{ $dealInCart
                                        ? 'In cart — open cart, '.$dealLabel.' pack is already in your cart'
                                        : 'Add '.$dealLabel.' '.$qtyExample.'-article pack to cart' }}">
                                @if($dealInCart)
                                    In cart ✓
                                @else
                                    Add {{ $qtyExample }} to cart
                                @endif
                            </button>
                        </article>
                    @endforeach
                    </div>
                    @endforeach
                </div>
            </div>

            <p class="catalog-bulk-empty small text-muted mb-0" data-bulk-empty hidden role="status">
                No bulk deal for this site.
            </p>

            {{-- Centered under the deals: batch pager (6 per page). --}}
            <nav class="catalog-bulk-pager"
                 data-bulk-pager
                 aria-label="Bulk deal pages">
                <button type="button"
                        class="catalog-bulk-nav"
                        data-bulk-scroll="prev"
                        aria-controls="bulkDealsRail"
                        aria-label="Previous bulk deals page">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="catalog-bulk-pages" data-bulk-pages role="group" aria-label="Page numbers">
                    @for($i = 1; $i <= $bulkPageCount; $i++)
                        <button type="button"
                                class="catalog-bulk-page{{ $i === 1 ? ' is-active' : '' }}"
                                aria-label="Bulk deals page {{ $i }}"
                                @if($i === 1) aria-current="page" @endif>{{ $i }}</button>
                    @endfor
                </div>
                <button type="button"
                        class="catalog-bulk-nav"
                        data-bulk-scroll="next"
                        aria-controls="bulkDealsRail"
                        aria-label="Next bulk deals page">
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
                <p class="catalog-bulk-page-label visually-hidden mb-0" data-bulk-page-label>Page 1 of {{ $bulkPageCount }}</p>
            </nav>
        </div>
    </section>
    @endif
