    @if(isset($bulkDeals) && $bulkDeals->count())
    @php
        $bulkPageSize = 6;
        $bulkDealPages = $bulkDeals->values()->chunk($bulkPageSize);
        $bulkPageCount = max(1, $bulkDealPages->count());
        $bulkStartOpen = request('bulk_deals') == '1' || request('bulk_deals') === 1;
        $liveDealPercents = $bulkDeals->map(fn ($deal) => (float) ($deal->bulk_pack_discount_percent ?? 0))
            ->filter(fn ($pct) => $pct > 0);
        $liveDealMinPct = $liveDealPercents->isNotEmpty() ? (float) $liveDealPercents->min() : null;
        $liveDealMaxPct = $liveDealPercents->isNotEmpty() ? (float) $liveDealPercents->max() : null;
        $formatDealPct = static fn (?float $pct) => $pct === null
            ? ''
            : rtrim(rtrim(number_format($pct, 1), '0'), '.');
    @endphp
    {{-- Paged batches of 6 with a smooth R→L slide between pages (translateX).
         Autoplay advances slowly; hover/focus pauses. Search beside Hide.
         One section only — under Spendable (never duplicated).
         First page is server-rendered so the rail does not squash all cards
         into one flex row before catalog.js pages them. --}}
    <section class="card border-0 shadow-sm mb-3 catalog-bulk-section{{ $bulkStartOpen ? '' : ' is-collapsed' }}"
             data-bulk-rail
             data-bulk-page-size="6"
             data-bulk-start="{{ $bulkStartOpen ? 'open' : 'collapsed' }}"
             data-bulk-remember="catalog.bulkDeals.collapsed"
             aria-labelledby="bulkDealsHeading">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="min-w-0">
                <strong id="bulkDealsHeading">
                    <i class="fa-solid fa-tags me-1 text-success" aria-hidden="true"></i>
                    Bulk discount deals
                    <span class="badge rounded-pill catalog-bulk-count">{{ $bulkDeals->count() }}</span>
                </strong>
                <div class="small text-muted">
                    Add a 3-article pack to cart (adjust to 3–5 there)
                    @if($liveDealMinPct !== null && $liveDealMaxPct !== null)
                        @if(abs($liveDealMaxPct - $liveDealMinPct) < 0.05)
                            and save {{ $formatDealPct($liveDealMinPct) }}%
                        @else
                            and save {{ $formatDealPct($liveDealMinPct) }}–{{ $formatDealPct($liveDealMaxPct) }}%
                        @endif
                        on these live deals.
                    @else
                        — totals at checkout include the discount.
                    @endif
                </div>
            </div>

            <div class="catalog-bulk-controls">
                <p class="small text-muted mb-0 catalog-bulk-search-hint">Use the catalog search above to filter deals too.</p>
                <button type="button"
                        class="btn btn-sm btn-link catalog-bulk-toggle"
                        data-bulk-toggle
                        aria-expanded="{{ $bulkStartOpen ? 'true' : 'false' }}"
                        aria-controls="bulkDealsBody">
                    <span data-bulk-toggle-label>{{ $bulkStartOpen ? 'Hide' : 'Show' }}</span>
                </button>
            </div>
        </div>

        <div class="card-body" id="bulkDealsBody">
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
                    @foreach($pageDeals as $deal)
                        @php
                            $qtyExample = 3;
                            $list = 0.0;
                            $after = 0.0;
                            $pct = 0.0;
                            $badgeKind = 'bulk';
                            $pctLabel = null;
                            $dealHost = '';
                            $dealUrl = '';
                            $dealTld = '';
                            $dealName = '';
                            $dealSearch = '';
                            $dealFaviconChain = [];
                            $dealFaviconUrl = null;
                            try {
                                $qtyExample = (int) ($deal->bulk_pack_qty ?? 3);
                                $list = (float) ($deal->bulk_pack_list_total ?? round(((float) $deal->price) * $qtyExample, 2));
                                $after = (float) ($deal->bulk_pack_now_total ?? $list);
                                // Better-of % (custom may beat bulk) — never show a bulk badge
                                // that disagrees with the floored “now” total.
                                $pct = (float) ($deal->bulk_pack_discount_percent ?? $deal->bulk_discount_percent ?? 0);
                                $badgeKind = (string) ($deal->bulk_pack_badge_kind ?? 'bulk');
                                $pctLabel = $pct > 0
                                    ? '−'.rtrim(rtrim(number_format($pct, 1), '0'), '.').'%'
                                    : null;
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
                                }
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        @endphp
                        <article class="bulk-deal-card"
                                 data-bulk-card
                                 data-bulk-deal-card
                                 data-bulk-site-id="{{ $deal->id }}"
                                 data-bulk-host="{{ $dealHost }}"
                                 data-bulk-search-text="{{ $dealSearch }}">
                            <div class="bulk-deal-card__head">
                                @include('advertiser.partials.catalog-site-tile', [
                                    'label' => $dealHost !== '' ? $dealHost : $dealName,
                                    'size' => 'md',
                                    'faviconUrl' => $dealFaviconUrl,
                                    'faviconChain' => $dealFaviconChain,
                                ])
                                <div class="bulk-deal-card__identity min-w-0 flex-grow-1">
                                    @php
                                        $dealLabel = $dealHost !== '' ? $dealHost : $dealName;
                                        $dealUrlTitle = $dealUrl !== '' ? $dealUrl : $dealLabel;
                                    @endphp
                                    <span class="bulk-deal-card__name">{{ $dealLabel }}</span>
                                    @if($dealHost !== '')
                                        <a href="{{ route('advertiser.catalog.visit', $deal->id) }}"
                                           target="_blank"
                                           rel="noopener nofollow"
                                           class="bulk-deal-card__url"
                                           data-no-tip
                                           title="{{ $dealUrlTitle }}">{{ $dealHost }}</a>
                                    @endif
                                </div>
                                @if($pctLabel)
                                    <span class="bulk-deal-card__pct"
                                          data-no-tip
                                          title="{{ $badgeKind === 'sale'
                                              ? 'Site sale applies on this pack (better than the bulk rate)'
                                              : 'Bulk discount on '.$qtyExample.'–'.(int) config('site_promotions.bulk.max_qty', 5).' articles' }}">
                                        @if($badgeKind === 'sale')
                                            Sale {{ $pctLabel }}
                                        @else
                                            {{ $pctLabel }}
                                        @endif
                                    </span>
                                @endif
                            </div>

                            <div class="bulk-deal-card__metrics">
                                <span>DR <strong>{{ $deal->dr }}</strong></span>
                                <span>DA <strong>{{ $deal->da }}</strong></span>
                            </div>

                            <div class="bulk-deal-card__price">
                                <span class="bulk-deal-card__was">{{ format_money($list) }}</span>
                                <strong class="bulk-deal-card__now">{{ format_money($after) }}</strong>
                                <span class="bulk-deal-card__qty">for {{ $qtyExample }}</span>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-primary buy-now bulk-deal-card__cta"
                                    data-id="{{ $deal->id }}"
                                    data-base-price="{{ $deal->price }}"
                                    data-publisher-price="{{ $deal->original_price ?? $deal->price }}"
                                    data-name="{{ $dealName }}"
                                    data-bulk-hint="1"
                                    data-bulk-qty="{{ $qtyExample }}"
                                    aria-label="Add {{ $dealHost }} 3-article pack to cart">
                                Add 3 to cart
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
                <p class="catalog-bulk-page-label mb-0" data-bulk-page-label>Page 1 of {{ $bulkPageCount }}</p>
            </nav>
        </div>
    </section>
    @endif
