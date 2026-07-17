@extends('advertiser.layouts.app')

@section('content')

@php
    use Illuminate\Support\Str;
    $sites = $sites ?? collect();
    $favorites = $favorites ?? [];
    $blacklist = $blacklist ?? [];
    $cart = $cart ?? [];

    if (!function_exists('getCountryFlag')) {
        function getCountryFlag($countryCode){
            $code = strtoupper((string) $countryCode);
            if ($code === 'UK') $code = 'GB';
            if (strlen($code) < 2) return '';
            return mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
        }
    }

    if (!function_exists('getLanguageFlag')) {
        function getLanguageFlag($languageCode){
            $languageToCountry = [
                'en' => 'us', 'es' => 'es', 'fr' => 'fr', 'de' => 'de',
                'it' => 'it', 'pt' => 'pt', 'nl' => 'nl', 'zh' => 'cn', 'ar' => 'ae',
                'pl' => 'pl', 'sv' => 'se', 'da' => 'dk', 'no' => 'no',
                'fi' => 'fi', 'el' => 'gr', 'cs' => 'cz', 'hu' => 'hu',
                'ro' => 'ro', 'bg' => 'bg', 'hr' => 'hr', 'sk' => 'sk',
                'sl' => 'si', 'lt' => 'lt', 'lv' => 'lv', 'et' => 'ee',
                'ca' => 'es', 'gl' => 'es', 'eu' => 'es', 'cy' => 'gb',
                'gd' => 'gb', 'ga' => 'ie', 'lb' => 'lu', 'rm' => 'ch',
                'mt' => 'mt',
            ];
            $countryCode = $languageToCountry[strtolower((string) $languageCode)] ?? 'us';
            return getCountryFlag($countryCode);
        }
    }
@endphp

<div class="container-fluid">
    @include('components.ad-banners', ['placement' => 'marketplace', 'audience' => 'advertiser'])

    <!-- HEADER -->
    <div class="row mb-3">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Catalog</h2>
            <p class="text-muted mb-0">Browse verified publishers and explore available placement opportunities.</p>
        </div>
    </div>

    <!-- FILTERS SECTION -->
@php
    $moreFilterKeys = ['sponsored','favorites_filter','blacklist_filter','da_min','da_max','dr_min','dr_max','traffic_min','traffic_max','new_badge'];
    $moreFiltersOpen = collect($moreFilterKeys)->contains(fn ($k) => filled(request($k)));
    $activeFilterChips = [];
    if (request('site')) $activeFilterChips[] = ['label' => 'Recommended site', 'key' => 'site'];
    if (request('search')) $activeFilterChips[] = ['label' => 'Search: '.request('search'), 'key' => 'search'];
    if (request('category')) $activeFilterChips[] = ['label' => 'Category', 'key' => 'category'];
    if (request('country')) $activeFilterChips[] = ['label' => 'Country', 'key' => 'country'];
    if (request('price_min') || request('price_max')) $activeFilterChips[] = ['label' => 'Price', 'key' => 'price'];
    if (request('language')) $activeFilterChips[] = ['label' => 'Language', 'key' => 'language'];
    if (request('sponsored') == '1') $activeFilterChips[] = ['label' => 'Sponsored', 'key' => 'sponsored'];
    if (request('favorites_filter') == '1') $activeFilterChips[] = ['label' => 'Favorites', 'key' => 'favorites_filter'];
    if (request('blacklist_filter') == '1') $activeFilterChips[] = ['label' => 'Blacklist', 'key' => 'blacklist_filter'];
    if (request('da_min') || request('da_max')) $activeFilterChips[] = ['label' => 'DA (Domain Authority)', 'key' => 'da'];
    if (request('dr_min') || request('dr_max')) $activeFilterChips[] = ['label' => 'DR (Domain Rating)', 'key' => 'dr'];
    if (request('traffic_min') || request('traffic_max')) $activeFilterChips[] = ['label' => 'Traffic', 'key' => 'traffic'];
    if (request('new_badge') == '1') $activeFilterChips[] = ['label' => 'New sites', 'key' => 'new_badge'];
    $inventoryTotal = $sites->total();
    $inventoryFrom = $sites->getCollection()->min(fn ($s) => (float) $s->price);
    $filtersExpanded = count($activeFilterChips) > 0 || $moreFiltersOpen || request()->boolean('filters_open');
@endphp

{{-- Result-first teaser (CV2): inventory + price before heavy filter chrome --}}
<div class="catalog-inventory-teaser d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <div class="small">
        @if($inventoryTotal > 0)
            <strong class="text-dark">{{ number_format($inventoryTotal) }}</strong>
            {{ Str::plural('placement', $inventoryTotal) }} available
            @if($inventoryFrom !== null)
                · from <strong style="color:#0b6266;">€{{ number_format($inventoryFrom, 2) }}</strong>
            @endif
        @else
            <span class="text-muted">No placements match yet — broaden filters below</span>
        @endif
    </div>
    <button type="button"
            class="btn btn-sm btn-outline-secondary"
            id="toggleCatalogFilters"
            aria-expanded="{{ $filtersExpanded ? 'true' : 'false' }}"
            aria-controls="catalogFiltersPanel">
        <i class="fa fa-sliders me-1" aria-hidden="true"></i>
        <span id="toggleCatalogFiltersLabel">{{ $filtersExpanded ? 'Hide filters' : 'Show filters' }}</span>
    </button>
</div>

<div class="row mb-3 {{ $filtersExpanded ? '' : 'd-none' }}" id="catalogFiltersPanel">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm catalog-filters-card">
            <div class="card-body py-3">
                <form method="GET" action="{{ route('advertiser.catalog') }}" id="filterForm">
                    <input type="hidden" name="filters_open" value="1">
                    <div class="row g-2 g-md-3 align-items-end">
                        <!-- Primary: Search (site + category/country/language text) -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                            <input type="text"
                                   name="search"
                                   class="form-control form-control-sm"
                                   placeholder="Site, category, country, language…"
                                   value="{{ request('search') }}"
                                   autocomplete="off">
                        </div>

                        <!-- Primary: Category (searchable dropdown) -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Category</label>
                            <div class="multi-select-wrapper" data-multi-select="category">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('categoryMultiDropdown', this)">
                                    <div class="selected-items" id="selectedCategoriesDisplay">
                                        <span class="placeholder-text">Select categories...</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="categoryMultiDropdown" role="listbox">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="categorySearch" class="form-control form-control-sm" placeholder="Type to search categories…" onkeyup="filterMultiOptions('categoryMultiOptions', this.value)" autocomplete="off">
                                    </div>
                                    <div class="options-list" id="categoryMultiOptions">
                                        @foreach($siteCategories as $category)
                                            <label class="option-item">
                                                <input type="checkbox" value="{{ $category }}" data-type="category" data-name="{{ $category }}" onchange="updateMultiFilter(this)">
                                                <span>{{ $category }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none">No categories found</div>
                                </div>
                            </div>
                            <input type="hidden" name="category" id="selectedCategory" value="{{ request('category') }}">
                        </div>

                        <!-- Primary: Country (searchable dropdown) -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Country</label>
                            <div class="multi-select-wrapper" data-multi-select="country">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('countryMultiDropdown', this)">
                                    <div class="selected-items" id="selectedCountriesDisplay">
                                        <span class="placeholder-text">Select countries...</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="countryMultiDropdown" role="listbox">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="countrySearch" class="form-control form-control-sm" placeholder="Type to search countries…" onkeyup="filterMultiOptions('countryMultiOptions', this.value)" autocomplete="off">
                                    </div>
                                    <div class="options-list" id="countryMultiOptions">
                                        @foreach($availableCountries as $code => $name)
                                            <label class="option-item">
                                                <input type="checkbox" value="{{ $code }}" data-type="country" data-name="{{ $name }}" onchange="updateMultiFilter(this)">
                                                <span>{{ $name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none">No countries found</div>
                                </div>
                            </div>
                            <input type="hidden" name="country" id="selectedCountry" value="{{ request('country') }}">
                        </div>

                        <!-- Primary: Language (searchable dropdown) -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Language</label>
                            <div class="multi-select-wrapper" data-multi-select="language">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('languageMultiDropdown', this)">
                                    <div class="selected-items" id="selectedLanguagesDisplay">
                                        <span class="placeholder-text">Select languages...</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="languageMultiDropdown" role="listbox">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="languageSearch" class="form-control form-control-sm" placeholder="Type to search languages…" onkeyup="filterMultiOptions('languageMultiOptions', this.value)" autocomplete="off">
                                    </div>
                                    <div class="options-list" id="languageMultiOptions">
                                        @foreach($availableLanguages as $code => $name)
                                            <label class="option-item">
                                                <input type="checkbox" value="{{ $code }}" data-type="language" data-name="{{ $name }}" onchange="updateMultiFilter(this)">
                                                <span>{{ $name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none">No languages found</div>
                                </div>
                            </div>
                            <input type="hidden" name="language" id="selectedLanguage" value="{{ request('language') }}">
                        </div>

                        <!-- Primary: Price -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Price (€)</label>
                            <div class="d-flex gap-2">
                                <input type="number"
                                       name="price_min"
                                       id="priceMinInput"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Min"
                                       min="0" step="0.01"
                                       value="{{ request('price_min') }}">
                                <input type="number"
                                       name="price_max"
                                       id="priceMaxInput"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Max"
                                       min="0" step="0.01"
                                       value="{{ request('price_max') }}">
                            </div>
                            <div class="filter-presets" data-preset-group="price">
                                <button type="button" class="filter-preset" data-min="" data-max="50" data-target-min="priceMinInput" data-target-max="priceMaxInput">Under €50</button>
                                <button type="button" class="filter-preset" data-min="50" data-max="150" data-target-min="priceMinInput" data-target-max="priceMaxInput">€50–150</button>
                                <button type="button" class="filter-preset" data-min="150" data-max="" data-target-min="priceMinInput" data-target-max="priceMaxInput">€150+</button>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-primary px-3" id="applyFiltersBtn">
                                    <i class="fa-solid fa-filter me-1"></i> Filter
                                </button>
                                <button type="button" class="btn btn-sm btn-cta-secondary px-2" id="toggleMoreFiltersBtn" aria-expanded="{{ $moreFiltersOpen ? 'true' : 'false' }}">
                                    More
                                    @if($moreFiltersOpen)
                                        <span class="badge rounded-pill ms-1" style="background:#0b6266;">{{ collect($moreFilterKeys)->filter(fn($k) => filled(request($k)))->count() }}</span>
                                    @endif
                                </button>
                                <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-cta-tertiary px-1">
                                    Reset
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- More filters drawer -->
                    <div id="moreFiltersDrawer" class="mt-3 pt-3 border-top" style="{{ $moreFiltersOpen ? '' : 'display:none;' }}">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Sponsored</label>
                                <select name="sponsored" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('sponsored') == '1' ? 'selected' : '' }}>Sponsored Only</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Favorites</label>
                                <select name="favorites_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('favorites_filter') == '1' ? 'selected' : '' }}>Favorites Only</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Blacklist</label>
                                <select name="blacklist_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('blacklist_filter') == '1' ? 'selected' : '' }}>Blacklisted Only</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <abbr class="metric-abbr text-decoration-none" title="Moz Domain Authority — site strength score from 0–100">DA</abbr>
                                </label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="da_min" id="daMinInput" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('da_min') }}">
                                    <input type="number" name="da_max" id="daMaxInput" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('da_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="da">
                                    <button type="button" class="filter-preset" data-min="20" data-max="" data-target-min="daMinInput" data-target-max="daMaxInput">DA 20+</button>
                                    <button type="button" class="filter-preset" data-min="40" data-max="" data-target-min="daMinInput" data-target-max="daMaxInput">DA 40+</button>
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <abbr class="metric-abbr text-decoration-none" title="Ahrefs Domain Rating — backlink strength score from 0–100">DR</abbr>
                                </label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="dr_min" id="drMinInput" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('dr_min') }}">
                                    <input type="number" name="dr_max" id="drMaxInput" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('dr_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="dr">
                                    <button type="button" class="filter-preset" data-min="30" data-max="" data-target-min="drMinInput" data-target-max="drMaxInput">DR 30+</button>
                                    <button type="button" class="filter-preset" data-min="50" data-max="" data-target-min="drMinInput" data-target-max="drMaxInput">DR 50+</button>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Monthly Traffic</label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="traffic_min" id="trafficMinInput" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('traffic_min') }}">
                                    <input type="number" name="traffic_max" id="trafficMaxInput" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('traffic_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="traffic">
                                    <button type="button" class="filter-preset" data-min="10000" data-max="" data-target-min="trafficMinInput" data-target-max="trafficMaxInput">10k+</button>
                                    <button type="button" class="filter-preset" data-min="50000" data-max="" data-target-min="trafficMinInput" data-target-max="trafficMaxInput">50k+</button>
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">New Sites</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="new_badge" id="new_badge" value="1" {{ request('new_badge') == 1 ? 'checked' : '' }}>
                                    <label class="form-check-label" for="new_badge">Show New Sites</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                @if(count($activeFilterChips))
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3" id="activeFilterChips">
                        <span class="small text-muted me-1">Active:</span>
                        @foreach($activeFilterChips as $chip)
                            <span class="badge rounded-pill filter-chip">{{ $chip['label'] }}</span>
                        @endforeach
                        <a href="{{ route('advertiser.catalog') }}" class="small ms-1" style="color:#0b6266;font-weight:600;">Clear all</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<style>
.filter-chip {
    background: rgba(78, 205, 203, 0.18) !important;
    color: #0b6266 !important;
    font-weight: 600;
    border: 1px solid #c8ebe9;
}
#toggleMoreFiltersBtn[aria-expanded="true"] {
    border-color: #0b6266;
    color: #0b6266;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const filtersPanel = document.getElementById('catalogFiltersPanel');
    const filtersToggle = document.getElementById('toggleCatalogFilters');
    const filtersToggleLabel = document.getElementById('toggleCatalogFiltersLabel');
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            const currentlyOpen = !filtersPanel.classList.contains('d-none');
            filtersPanel.classList.toggle('d-none', currentlyOpen);
            filtersToggle.setAttribute('aria-expanded', currentlyOpen ? 'false' : 'true');
            if (filtersToggleLabel) {
                filtersToggleLabel.textContent = currentlyOpen ? 'Show filters' : 'Hide filters';
            }
        });
    }

    const btn = document.getElementById('toggleMoreFiltersBtn');
    const drawer = document.getElementById('moreFiltersDrawer');
    if (btn && drawer) {
        btn.addEventListener('click', function () {
            const open = drawer.style.display !== 'none';
            drawer.style.display = open ? 'none' : 'block';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
    }

    // FR2 — preset chips set min/max inputs
    document.querySelectorAll('.filter-preset').forEach(function (chip) {
        chip.addEventListener('click', function () {
            const minEl = document.getElementById(chip.dataset.targetMin);
            const maxEl = document.getElementById(chip.dataset.targetMax);
            if (!minEl || !maxEl) return;
            minEl.value = chip.dataset.min || '';
            maxEl.value = chip.dataset.max || '';
            const group = chip.closest('.filter-presets');
            if (group) {
                group.querySelectorAll('.filter-preset').forEach(c => c.classList.remove('is-active'));
            }
            chip.classList.add('is-active');
        });
    });
});
</script>



    <!-- CONTENT AREA -->
    <div class="row">
        <div class="col-md-12">

            @php
                $resultTotal = $sites->total();
                $hasActiveFilters = count($activeFilterChips) > 0;
                $sortValue = request('sort', 'dr_desc');
            @endphp

            <div class="catalog-results-bar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="text-muted small">
                    @if($resultTotal > 0)
                        Showing
                        <strong class="text-dark">{{ $sites->firstItem() }}–{{ $sites->lastItem() }}</strong>
                        of <strong class="text-dark">{{ number_format($resultTotal) }}</strong>
                        {{ Str::plural('site', $resultTotal) }}
                    @else
                        No sites match your filters
                    @endif
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label for="catalogSort" class="small text-muted mb-0">Sort</label>
                    <select id="catalogSort"
                            name="sort"
                            form="filterForm"
                            class="form-select form-select-sm"
                            style="width: auto; min-width: 160px;"
                            onchange="document.getElementById('filterForm').submit()">
                        <option value="dr_desc" @selected($sortValue === 'dr_desc')>DR (high → low)</option>
                        <option value="da_desc" @selected($sortValue === 'da_desc')>DA (high → low)</option>
                        <option value="traffic_desc" @selected($sortValue === 'traffic_desc')>Traffic (high → low)</option>
                        <option value="price_asc" @selected($sortValue === 'price_asc')>Price (low → high)</option>
                        <option value="price_desc" @selected($sortValue === 'price_desc')>Price (high → low)</option>
                        <option value="newest" @selected($sortValue === 'newest')>Newest first</option>
                    </select>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <p class="small text-muted mb-0">
                    Searching for a site that isn’t listed yet?
                </p>
                <button type="button" class="btn btn-sm btn-outline-success btn-suggest-website"
                        data-search="{{ request('search') }}">
                    <i class="fa-solid fa-lightbulb me-1"></i> Suggest a website
                </button>
            </div>

            @if(isset($bulkDeals) && $bulkDeals->count())
            <div class="card border-0 shadow-sm mb-3 catalog-bulk-section">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong><i class="fa-solid fa-tags me-1 text-success"></i> Bulk discount deals</strong>
                        <div class="small text-muted">Buy 3–5 articles on these sites and save 10–15%. Totals at checkout include the discount.</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($bulkDeals as $deal)
                            @php
                                $unit = (float) $deal->price;
                                $pct = (float) $deal->bulk_discount_percent;
                                $qtyExample = 3;
                                $list = round($unit * $qtyExample, 2);
                                $save = round($list * ($pct / 100), 2);
                                $after = round($list - $save, 2);
                            @endphp
                            <div class="col-md-4 col-lg-3">
                                <div class="bulk-deal-card h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                        <div class="fw-semibold text-truncate">{{ $deal->site_name }}</div>
                                        <span class="badge bg-success-subtle text-success border">−{{ rtrim(rtrim(number_format($pct, 1), '0'), '.') }}%</span>
                                    </div>
                                    <div class="small text-muted mb-2">DR {{ $deal->dr }} · DA {{ $deal->da }}</div>
                                    <div class="small">
                                        <span class="text-decoration-line-through text-muted">€{{ number_format($list, 2) }}</span>
                                        for {{ $qtyExample }} →
                                        <strong class="text-success">€{{ number_format($after, 2) }}</strong>
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary mt-2 buy-now w-100"
                                            data-id="{{ $deal->id }}"
                                            data-base-price="{{ $deal->price }}"
                                            data-name="{{ $deal->site_name }}"
                                            data-bulk-hint="1">
                                        Add to cart
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            <!-- Publishers Table -->
            <div class="card border-0 shadow-sm catalog-results-card">
                <div class="card-body p-0">
                    
                    <div class="table-responsive catalog-table-scroll d-none d-md-block">
    <table class="table table-borderless align-middle mb-0 data-table catalog-table">
        <thead class="table-light">
            <tr>
                <th class="text-start catalog-th" style="min-width: 250px;">
                    <span class="catalog-th-label">
                        Site
                        <x-glass-tip
                            title="Site"
                            body="Domains are partially masked to protect publisher inventory. Reveal the URL to inspect before buying — full access stays tied to your order."
                            label="About Site column"
                            placement="bottom" />
                    </span>
                </th>
                <th class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Category
                        <x-glass-tip
                            title="Category"
                            body="Topic niches this site accepts for guest posts and placements."
                            label="About Category column"
                            placement="bottom" />
                    </span>
                </th>
                <th class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Traffic
                        <x-glass-tip
                            title="Monthly Traffic"
                            body="Estimated monthly visits from Semrush. Higher traffic usually means more reach for your placement."
                            label="About Traffic column"
                            placement="bottom" />
                    </span>
                </th>
                <th class="text-center catalog-th">
                    <span class="catalog-th-label">
                        DR
                        <x-glass-tip
                            title="Domain Rating (DR)"
                            body="Ahrefs Domain Rating (0–100): how strong the site’s backlink profile is compared to others on the web."
                            label="About Domain Rating"
                            placement="bottom" />
                    </span>
                </th>
                <th class="text-center catalog-th">
                    <span class="catalog-th-label">
                        DA
                        <x-glass-tip
                            title="Domain Authority (DA)"
                            body="Moz Domain Authority (0–100): an overall site authority score used to compare ranking potential."
                            label="About Domain Authority"
                            placement="bottom" />
                    </span>
                </th>
                <th class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Country
                        <x-glass-tip
                            title="Country"
                            body="Primary country / audience market for this publisher website."
                            label="About Country column"
                            placement="bottom" />
                    </span>
                </th>
                <th class="text-center catalog-th catalog-th-action" style="min-width: 180px;">
                    <span class="catalog-th-label">
                        Action
                        <x-glass-tip
                            title="Actions"
                            body="Buy a placement, save the site to favorites, or blacklist it so it stays out of your way."
                            label="About Action column"
                            placement="bottom" />
                    </span>
                </th>
            </tr>
        </thead>
        <tbody>
            @forelse($sites as $site)
            @php
                $isBlacklisted = in_array($site->id, $blacklist);
                $isFavorited = in_array($site->id, $favorites);
                // Decode sensitive prices
                $sensitivePrices = $site->sensitive_prices;
                if (is_string($sensitivePrices)) {
                    $sensitivePrices = json_decode($sensitivePrices, true);
                }
                $sensitivePrices = is_array($sensitivePrices) ? $sensitivePrices : [];
            @endphp
            <tr class="site-row {{ $isBlacklisted ? 'blacklisted-row' : '' }}" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}" style="{{ $isBlacklisted ? 'opacity: 0.7; background-color: #fff3f3;' : '' }}">
                
                <td class="catalog-site-cell" style="min-width: 250px;">
                    @php
                        // Dynamic "new" flag — listing created within the last 30 days
                        $isNew = $site->created_at->gt(now()->subDays(30));
                    @endphp

                    @php
                        $rawHost = (string) Str::of($site->site_url)
                            ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
                            ->before('/');
                        $hostParts = explode('.', $rawHost);
                        if (count($hostParts) >= 2) {
                            $tld = array_pop($hostParts);
                            $namePart = implode('.', $hostParts);
                            $visibleLen = min(4, max(2, strlen($namePart)));
                            $maskedHost = substr($namePart, 0, $visibleLen) . '***.' . $tld;
                        } else {
                            $maskedHost = substr($rawHost, 0, 3) . '******';
                        }
                    @endphp

                    <div class="catalog-site-stack">
                        <!-- URL Row -->
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="text-dark catalog-site-url"
                                  id="url-masked-{{ $site->id }}"
                                  data-glass-tip
                                  data-glass-tip-title="Masked for publishers"
                                  data-glass-tip-body="We hide part of the domain so publisher inventory isn’t scraped. Metrics stay visible — reveal the full URL when you’re ready to buy."
                                  data-glass-tip-placement="top">
                                {{ $maskedHost }}
                            </span>

                            <span class="url-full text-muted d-none catalog-site-url"
                                  id="url-full-{{ $site->id }}">
                                {{ $rawHost }}
                            </span>

                            @if($site->isFeatured())
                                <span class="site-chip site-chip--featured"
                                      title="Featured placement — higher visibility in the catalog">
                                    <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                                    <span>Featured</span>
                                </span>
                            @endif

                            @if($site->hasActiveCustomDiscount())
                                <span class="site-chip site-chip--sale"
                                      title="Limited-time publisher discount">
                                    <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                    <span>−{{ rtrim(rtrim(number_format((float) $site->custom_discount_percent, 1), '0'), '.') }}%</span>
                                </span>
                            @endif

                            @if($site->joinsBulkDiscount())
                                <span class="site-chip site-chip--bulk"
                                      title="Bulk discount available on 3–5 articles">
                                    <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                                    <span>Bulk −{{ rtrim(rtrim(number_format((float) $site->bulk_discount_percent, 1), '0'), '.') }}%</span>
                                </span>
                            @endif

                            @if($site->verified)
                                <button type="button"
                                        class="site-chip site-chip--verified"
                                        data-glass-tip
                                        data-glass-tip-title="Verified Publisher"
                                        data-glass-tip-body="This publisher has successfully completed our verification process and meets our platform's quality standards."
                                        data-glass-tip-placement="top"
                                        aria-label="Verified publisher">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    <span>Verified</span>
                                </button>
                            @endif

                            @if($isNew)
                                <button type="button"
                                        class="site-badge-new"
                                        data-glass-tip
                                        data-glass-tip-title="New Listing"
                                        data-glass-tip-body="Added in the last 30 days — fresh inventory worth reviewing early."
                                        data-glass-tip-placement="top"
                                        aria-label="New listing">
                                    NEW
                                </button>
                            @endif

                            <button class="btn btn-sm btn-link text-secondary p-0 toggle-url btn-icon-quiet"
                                    data-id="{{ $site->id }}"
                                    title="Reveal or hide full URL"
                                    aria-label="Reveal or hide full URL"
                                    style="font-size: 15px;">
                                <i class="fa-regular fa-eye"></i>
                            </button>

                            <a href="{{ $site->site_url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-muted"
                               title="Open website"
                               aria-label="Open website in new tab"
                               style="display:inline-flex; align-items:center; text-decoration:none;">
                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 13px;" aria-hidden="true"></i>
                            </a>

                            <button type="button"
                                    class="btn btn-sm btn-link text-muted p-0 expand-arrow"
                                    id="arrow-{{ $site->id }}"
                                    aria-label="Expand site details"
                                    aria-expanded="false"
                                    style="font-size: 13px; line-height: 1;">
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </button>
                        </div>

                        @if($isBlacklisted)
                        <div class="site-status-row" role="list" aria-label="Site status">
                            <span class="site-chip site-chip--blacklist"
                                  role="listitem"
                                  tabindex="0"
                                  data-glass-tip
                                  data-glass-tip-title="Blacklisted"
                                  data-glass-tip-body="You blacklisted this site — it stays dimmed in your catalog until you remove it."
                                  data-glass-tip-placement="top"
                                  aria-label="Blacklisted site details">
                                <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                <span>Blacklisted</span>
                            </span>
                        </div>
                        @endif

                        <!-- DoFollow Links -->
                        <div class="text-muted catalog-site-meta">
                            Max 03 DoFollow links
                        </div>

                        <!-- Turnaround Time -->
                        <div>
                            <span class="turnaround-badge catalog-site-meta">
                                Turnaround: {{ $site->turnaround_time ?? 'N/A' }}
                            </span>
                        </div>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell">
                   @php
    $categoryArray = [];

    // Handle categories array
    if (!empty($site->categories) && is_array($site->categories)) {

        foreach ($site->categories as $cat) {

            if (str_contains($cat, ',')) {
                $splitCats = array_map('trim', explode(',', $cat));
                $categoryArray = array_merge($categoryArray, $splitCats);
            } else {
                $categoryArray[] = trim($cat);
            }
        }
    }

    // Fallback to category string
    elseif (!empty($site->category)) {

        if (str_contains($site->category, ',')) {
            $categoryArray = array_map('trim', explode(',', $site->category));
        } else {
            $categoryArray[] = trim($site->category);
        }
    }

    // Clean array
    $categoryArray = array_values(array_unique(array_filter($categoryArray)));

    $showLimit = 3;
    $totalCategories = count($categoryArray);
@endphp

@if(count($categoryArray))
    <div class="categories-wrapper">

        <div class="categories-column">

            @foreach($categoryArray as $index => $cat)

                <span class="category-badge {{ $index >= $showLimit ? 'extra-category d-none' : '' }}">
                    {{ $cat }}
                </span>

            @endforeach

        </div>

        @if($totalCategories > $showLimit)
            <button type="button"
                    class="toggle-cats-btn"
                    onclick="
                        const wrapper = this.closest('.categories-wrapper');
                        const hiddenItems = wrapper.querySelectorAll('.extra-category');

                        hiddenItems.forEach(el => el.classList.toggle('d-none'));

                        this.innerText = this.innerText.includes('more')
                            ? 'Show less'
                            : '+{{ $totalCategories - $showLimit }} more';
                    ">
                +{{ $totalCategories - $showLimit }} more
            </button>
        @endif

    </div>
@endif
                </td>

                <td class="text-center catalog-stat-cell">
                    <div class="catalog-stat">
                        <img src="{{ asset('assets/img/traffic.svg') }}" alt="" style="width: 16px; height: 16px;" onerror="this.style.display='none'">
                        <span class="fw-semibold">{{ number_format($site->traffic) }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell">
                    <div class="catalog-stat">
                        <img src="{{ asset('assets/img/ahref.jpeg') }}" alt="" style="width: 16px; height: 16px; border-radius: 2px;" onerror="this.style.display='none'">
                        <span class="fw-semibold text-info">{{ $site->dr }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell">
                    <div class="catalog-stat">
                        <img src="{{ asset('assets/img/moz_da.png') }}" alt="" style="width: 16px; height: 16px;" onerror="this.style.display='none'">
                        <span class="fw-semibold text-primary">{{ $site->da }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell">
                    @php
                        $countryCode = $site->primaryCountryCode() ?: $site->country;
                    @endphp
                    <div class="d-flex flex-column align-items-center gap-1">
                        <span style="font-size: 22px; line-height: 1;" aria-hidden="true">{!! getCountryFlag($countryCode) !!}</span>
                        <span class="text-muted small text-center">{{ fullCountry($countryCode) }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell catalog-td-action">
                    <div class="catalog-row-actions">
                        @php
                            $catalogSalePct = $site->activeCustomDiscountPercent();
                            $catalogSalePrice = $catalogSalePct
                                ? round((float) $site->price * (1 - $catalogSalePct / 100), 2)
                                : null;
                        @endphp
                        <button class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2"
                                data-id="{{ $site->id }}"
                                data-base-price="{{ $site->price }}"
                                data-name="{{ $site->site_name }}"
                                aria-label="Buy placement for {{ $site->site_name }}">
                            <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                            <span>Buy</span>
                            @if($catalogSalePrice !== null)
                                <span class="small text-decoration-line-through opacity-75">€{{ number_format((float) $site->price, 2) }}</span>
                                <span class="fw-semibold base-price-display">€{{ number_format($catalogSalePrice, 2) }}</span>
                            @else
                                <span class="fw-semibold base-price-display">€{{ number_format($site->price, 2) }}</span>
                            @endif
                        </button>

                        <div class="catalog-row-actions-quiet">
                            <button type="button"
                                    class="btn-icon-quiet favorite-btn {{ $isFavorited ? 'is-active' : '' }}"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    aria-label="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}"
                                    title="{{ $isFavorited ? 'Remove from Favorites' : 'Add to Favorites' }}">
                                <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart" aria-hidden="true"></i>
                            </button>

                            <button type="button"
                                    class="btn-icon-quiet blacklist-btn {{ $isBlacklisted ? 'is-active' : '' }}"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    aria-label="{{ $isBlacklisted ? 'Remove from blacklist' : 'Blacklist site' }}"
                                    title="{{ $isBlacklisted ? 'Remove from Blacklist' : 'Blacklist Site' }}">
                                <i class="fa-solid fa-ban" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </td>
            </tr>
            
            <tr class="expanded-row-{{ $site->id }}" style="display: none;">
    <td colspan="7" style="background-color: #f9f9f9; padding: 20px;">
        <div class="row">
            <div class="col-md-12">
                <h6 class="mb-3">Site Details</h6>

                {{-- Expandable panel: screenshot + tags/DF links + sample only (no DR/DA/traffic/country) --}}
                <div class="row align-items-start g-4">

                    <div class="col-md-3 text-center">
                        <p class="small text-muted mb-2"><strong>Homepage preview</strong></p>
                        @php
                            // Prefer admin-uploaded site_image, then auto screenshot
                            $previewPath = $site->site_image ?: $site->screenshot_path;
                            $previewUrl = $previewPath ? asset('storage/' . $previewPath) : null;
                        @endphp
                        @if($previewUrl)
                            <div class="site-preview-zoom">
                                <img src="{{ $previewUrl }}"
                                     alt="{{ $site->site_name }} homepage preview"
                                     loading="lazy"
                                     class="site-image-thumbnail"
                                     onerror="this.onerror=null;this.closest('.site-preview-zoom').classList.add('is-broken');">
                            </div>
                            <div class="site-preview-fallback bg-light border rounded d-none align-items-center justify-content-center">
                                <i class="fa-solid fa-image text-muted" style="font-size: 32px;"></i>
                            </div>
                        @else
                            <div class="site-preview-fallback bg-light border rounded d-inline-flex align-items-center justify-content-center">
                                <i class="fa-solid fa-image text-muted" style="font-size: 32px;"></i>
                            </div>
                        @endif
                    </div>

                    <div class="col-md-5">
                        <p class="mb-1"><strong class="small">Description</strong></p>
                        <div class="text-muted small">
                            {!! $site->description !!}
                        </div>
                        <div class="text-muted small mt-2">
                            <strong>DoFollow links:</strong> Max 03 DoFollow links
                        </div>
                        @if($site->lastPublicationLabel())
                            <p class="text-muted small mb-0 mt-1" style="color:#94a3b8 !important;">
                                {{ $site->lastPublicationLabel() }}
                            </p>
                        @endif

                        @php
                            $avg = (float) ($site->rating_avg ?? 0);
                            $count = (int) ($site->rating_count ?? 0);
                            $roundedAvg = (int) round($avg);
                            $completionPct = $site->completionRatePercent();
                        @endphp
                        <div class="site-trust-compact mt-2" data-site-id="{{ $site->id }}">
                            <span class="site-trust-compact__stars" aria-label="Average rating {{ $count > 0 ? number_format($avg, 1) : 'new' }} out of 5">
                                @for($i = 1; $i <= 5; $i++)
                                    <i class="fa-{{ $i <= $roundedAvg && $count > 0 ? 'solid' : 'regular' }} fa-star" aria-hidden="true"></i>
                                @endfor
                                <span class="site-trust-compact__score">{{ $count > 0 ? number_format($avg, 1) : 'New' }}</span>
                            </span>
                            <span class="site-trust-compact__sep" aria-hidden="true">·</span>
                            <span class="site-trust-compact__orders" title="Share of orders completed successfully">
                                @if($completionPct !== null)
                                    {{ $completionPct }}% completed
                                @else
                                    No completions yet
                                @endif
                            </span>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <p><strong>Tags:</strong></p>

                        <div class="d-flex flex-column gap-2">
                            <div>
                                @if($site->link_type)
                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Link Type">
                                        <i class="fa-solid fa-link me-1"></i>{{ $site->link_type }}
                                    </span>
                                @else
                                    <span class="text-muted small">No link type specified</span>
                                @endif
                            </div>

                            <div class="d-flex flex-wrap gap-1">
                                @if($site->sponsored)
                                    <span class="badge bg-warning-subtle text-dark border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Sponsored placement">
                                        <i class="fa-solid fa-star me-1"></i>Sponsored
                                    </span>
                                @endif

                                @if($site->partner_material)
                                    <span class="badge bg-success-subtle text-success border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Partner content allowed">
                                        <i class="fa-solid fa-handshake me-1"></i>Partner
                                    </span>
                                @endif

                                @if($site->as_you_prefer ?? false)
                                    <span class="badge bg-primary-subtle text-primary border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Flexible placement">
                                        <i class="fa-solid fa-sliders-h me-1"></i>As You Prefer
                                    </span>
                                @endif

                                @if(!$site->sponsored && !$site->partner_material && !($site->as_you_prefer ?? false))
                                    <span class="text-muted small">No additional tags</span>
                                @endif
                            </div>

                            <div>
                                @if(!empty($sensitivePrices))
                                    <p><strong>Sensitive Prices (Additional Charges):</strong></p>

                                    <div class="sensitive-prices-group"
                                         data-site-id="{{ $site->id }}"
                                         data-base-price="{{ $site->price }}"
                                         role="radiogroup"
                                         aria-label="Sensitive topic pricing">

                                        <div class="form-check mb-2">
                                            <input class="form-check-input sensitive-price-checkbox"
                                                   type="radio"
                                                   name="sensitive_prices_{{ $site->id }}"
                                                   value="0"
                                                   data-type="none"
                                                   data-additional-price="0"
                                                   data-total-price="{{ $site->price }}"
                                                   data-site-id="{{ $site->id }}"
                                                   id="sensitive_{{ $site->id }}_none"
                                                   checked>
                                            <label class="form-check-label" for="sensitive_{{ $site->id }}_none">
                                                <strong>No sensitive topic</strong>
                                                <span class="text-muted">Base price</span>
                                            </label>
                                        </div>

                                        @foreach($sensitivePrices as $type => $additionalPrice)
                                            @php
                                                $totalPrice = $site->price + $additionalPrice;
                                            @endphp

                                            <div class="form-check mb-2">
                                                <input class="form-check-input sensitive-price-checkbox"
                                                       type="radio"
                                                       name="sensitive_prices_{{ $site->id }}"
                                                       value="{{ $additionalPrice }}"
                                                       data-type="{{ $type }}"
                                                       data-additional-price="{{ $additionalPrice }}"
                                                       data-total-price="{{ $totalPrice }}"
                                                       data-site-id="{{ $site->id }}"
                                                       id="sensitive_{{ $site->id }}_{{ $loop->index }}">

                                                <label class="form-check-label"
                                                       for="sensitive_{{ $site->id }}_{{ $loop->index }}">
                                                    <strong>{{ ucfirst($type) }}</strong>
                                                    <span class="text-danger">
                                                        €{{ number_format($additionalPrice, 2) }}
                                                    </span>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="selected-price-info mt-2"
                                         id="price-info-{{ $site->id }}">
                                        <small class="text-muted">
                                            Current price:
                                            <strong>€{{ number_format($site->price, 2) }}</strong>
                                            (Base price)
                                        </small>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <p><strong>Sample article:</strong></p>

                        <div class="d-flex flex-column gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <a href="{{ $site->example_url ?? '#' }}"
                                   target="_blank"
                                   class="text-decoration-none"
                                   style="word-break: break-all;">
                                    {{ Str::limit($site->example_url ?? 'Not available', 50) }}
                                </a>

                                @if($site->example_url)
                                    <a href="{{ $site->example_url }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-muted d-inline-flex align-items-center"
                                       title="Open sample article">
                                        <i class="fa-solid fa-arrow-up-right-from-square"
                                           style="font-size: 13px;"></i>
                                    </a>
                                @endif
                            </div>

                            @if($site->example_url)
                                <button class="btn btn-sm btn-outline-secondary copy-example-url"
                                        data-url="{{ $site->example_url }}"
                                        style="width: fit-content;">
                                    <i class="fa-regular fa-copy"></i> Copy URL
                                </button>
                            @endif

                            <div class="d-flex align-items-center gap-2">
                                <strong>Publication Duration:</strong>

                                @if($site->publication_time)
                                    <span class="badge text-muted border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Publication Duration">
                                        <i class="fa-solid fa-clock me-1"></i>
                                        {{ $site->publication_time }}
                                    </span>
                                @else
                                    <span class="text-muted small">
                                        No publication duration specified
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </td>
</tr>
            @empty
            <tr>
                <td colspan="7" class="text-center py-5">
                    <div class="catalog-empty-state mx-auto">
                        <div class="catalog-empty-icon" aria-hidden="true">
                            <i class="fa-solid fa-filter-circle-xmark"></i>
                        </div>
                        <h5 class="mb-2">
                            {{ $hasActiveFilters ? 'No sites match these filters' : 'No publishers available yet' }}
                        </h5>
                        <p class="text-muted mb-3">
                            {{ $hasActiveFilters
                                ? 'Try broader filters — clear a category, widen price, or remove DA/DR limits.'
                                : 'New verified sites show up here as publishers list them.' }}
                        </p>
                        @if($hasActiveFilters)
                            <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                                <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm">Clear all filters</a>
                                <a href="{{ route('advertiser.catalog', ['sort' => 'dr_desc']) }}" class="btn btn-outline-secondary btn-sm">Browse top DR</a>
                                <button type="button" class="btn btn-outline-success btn-sm btn-suggest-website"
                                        data-search="{{ request('search') }}">
                                    <i class="fa-solid fa-lightbulb me-1"></i> Suggest a website
                                </button>
                            </div>
                            <p class="small text-muted mb-0">
                                Can’t find a site you need?
                                @if(request('search'))
                                    Suggest “{{ request('search') }}” and we’ll try to add it.
                                @else
                                    Suggest it and we’ll try to include it in the marketplace.
                                @endif
                            </p>
                        @else
                            <a href="{{ route('advertiser.catalog', ['new_badge' => 1]) }}" class="btn btn-outline-secondary btn-sm">Show new sites</a>
                        @endif
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Mobile card list (R1) — same buy/favorite/blacklist actions --}}
<div class="catalog-mobile-list d-md-none p-3">
    @forelse($sites as $site)
        @php
            $isBlacklisted = in_array($site->id, $blacklist);
            $isFavorited = in_array($site->id, $favorites);
            $isNew = $site->created_at->gt(now()->subDays(30));
            $rawHost = (string) Str::of($site->site_url)
                ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
                ->before('/');
            $hostParts = explode('.', $rawHost);
            if (count($hostParts) >= 2) {
                $tld = array_pop($hostParts);
                $namePart = implode('.', $hostParts);
                $visibleLen = min(4, max(2, strlen($namePart)));
                $maskedHost = substr($namePart, 0, $visibleLen) . '***.' . $tld;
            } else {
                $maskedHost = substr($rawHost, 0, 3) . '******';
            }
            $mobileCategory = is_array($site->categories) && count($site->categories)
                ? $site->categories[0]
                : ($site->category ?? '—');
            if (is_string($mobileCategory) && str_contains($mobileCategory, ',')) {
                $mobileCategory = trim(explode(',', $mobileCategory)[0]);
            }
        @endphp
        <article class="catalog-mobile-card {{ $isBlacklisted ? 'is-blacklisted' : '' }}" data-id="{{ $site->id }}">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="min-w-0">
                    <div class="fw-semibold text-dark text-truncate catalog-site-url" id="url-masked-mobile-{{ $site->id }}">{{ $maskedHost }}</div>
                    <div class="url-full text-muted small d-none text-truncate" id="url-full-mobile-{{ $site->id }}">{{ $rawHost }}</div>
                    <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                        @if($site->verified)
                            <span class="site-chip site-chip--verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Verified</span></span>
                        @endif
                        @if($isNew)
                            <span class="site-badge-new" aria-label="New listing">NEW</span>
                        @endif
                        <span class="category-badge">{{ $mobileCategory }}</span>
                    </div>
                </div>
                <button type="button"
                        class="btn btn-sm btn-link text-secondary p-0 toggle-url btn-icon-quiet"
                        data-id="{{ $site->id }}"
                        data-url-prefix="mobile"
                        aria-label="Reveal or hide full URL">
                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                </button>
            </div>
            @php
                $mobileCountry = $site->primaryCountryCode() ?: $site->country;
            @endphp
            <div class="catalog-mobile-metrics">
                <div><span class="text-muted">Traffic</span><strong>{{ number_format($site->traffic) }}</strong></div>
                <div><span class="text-muted">DR</span><strong>{{ $site->dr }}</strong></div>
                <div><span class="text-muted">DA</span><strong>{{ $site->da }}</strong></div>
                <div><span class="text-muted">Country</span><strong>{!! getCountryFlag($mobileCountry) !!} {{ fullCountry($mobileCountry) }}</strong></div>
            </div>
            <div class="d-flex align-items-center gap-2 mt-3">
                <button class="btn btn-sm btn-primary buy-now flex-grow-1 d-inline-flex justify-content-center align-items-center gap-2"
                        data-id="{{ $site->id }}"
                        data-base-price="{{ $site->price }}"
                        data-name="{{ $site->site_name }}"
                        aria-label="Buy placement for {{ $site->site_name }}">
                    <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                    <span>Buy</span>
                    <span class="fw-semibold base-price-display">€{{ number_format($site->price, 2) }}</span>
                </button>
                <button type="button"
                        class="btn-icon-quiet favorite-btn {{ $isFavorited ? 'is-active' : '' }}"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        aria-label="{{ $isFavorited ? 'Remove from favorites' : 'Add to favorites' }}">
                    <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart" aria-hidden="true"></i>
                </button>
                <button type="button"
                        class="btn-icon-quiet blacklist-btn {{ $isBlacklisted ? 'is-active' : '' }}"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        aria-label="{{ $isBlacklisted ? 'Remove from blacklist' : 'Blacklist site' }}">
                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                </button>
            </div>
        </article>
    @empty
        <div class="catalog-empty-state mx-auto text-center py-4">
            <div class="catalog-empty-icon" aria-hidden="true"><i class="fa-solid fa-filter-circle-xmark"></i></div>
            <h5 class="mb-2">{{ $hasActiveFilters ? 'No sites match these filters' : 'No publishers available yet' }}</h5>
            <p class="text-muted mb-3">
                {{ $hasActiveFilters
                    ? 'Try broader filters — clear a category, widen price, or remove DA/DR limits.'
                    : 'New verified sites show up here as publishers list them.' }}
            </p>
            @if($hasActiveFilters)
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm">Clear all filters</a>
                    <button type="button" class="btn btn-outline-success btn-sm btn-suggest-website"
                            data-search="{{ request('search') }}">
                        <i class="fa-solid fa-lightbulb me-1"></i> Suggest a website
                    </button>
                </div>
            @endif
        </div>
    @endforelse
</div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-center mt-4 pb-3">
                        {{ $sites->links() }}
                    </div>

                </div>
            </div>
    
        </div>
    </div>

</div>

<style>
.table {
    border-radius: 12px;
    overflow: hidden;
}

.table thead th {
    border-bottom: 2px solid #e9ecef;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #495057;
    padding: var(--table-head-y, 14px) var(--table-cell-x, 12px);
}

.table tbody td {
    padding: var(--table-cell-y, 16px) var(--table-cell-x, 12px);
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

thead th {
    text-align: center;
}

.catalog-th {
    white-space: nowrap;
    vertical-align: middle;
}

.catalog-th-label {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    line-height: 1.2;
}

.catalog-th-label .glass-tip-trigger {
    margin-left: 2px;
    transform: translateY(-0.5px);
}

.catalog-stat-cell {
    text-align: center !important;
    vertical-align: middle;
}

.catalog-stat {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 4.5rem;
    line-height: 1.2;
}

.catalog-stat .fw-semibold {
    font-variant-numeric: tabular-nums;
    font-size: 0.95rem;
}

.categories-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.categories-wrapper .categories-column {
    align-items: center;
}

.categories-wrapper .toggle-cats-btn {
    text-align: center;
}

.table tbody tr.site-row {
    transition: background-color 0.2s ease;
    cursor: pointer;
}

.table tbody tr.site-row:hover {
    background-color: #f5f9ff !important;
}

.table tbody tr.blacklisted-row:hover {
    background-color: #ffe6e6 !important;
}

.table tbody tr[class*="expanded-row"]:hover {
    background-color: #f9f9f9 !important;
}

.btn-link {
    text-decoration: none;
}

.btn-link:hover {
    background-color: #f1f3f5;
    border-radius: 4px;
}

.badge {
    font-size: 0.75rem;
    font-weight: 500;
}

.favorite-btn.btn-danger {
    background-color: #dc3545 !important;
    color: white !important;
}

.blacklist-btn.btn-dark {
    background-color: #6c757d !important;
    color: white !important;
}

.buy-now:hover {
    background-color: #0b6266 !important;
}

.rotate-arrow {
    transform: rotate(180deg);
}

@media (max-width: 768px) {
    .btn-sm {
        font-size: 0.75rem;
    }

    .catalog-filters-card .card-body {
        padding-top: 0.85rem;
        padding-bottom: 0.85rem;
    }
}

.catalog-mobile-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    padding: 14px;
    margin-bottom: 12px;
}
.catalog-mobile-card.is-blacklisted {
    opacity: 0.75;
    background: #fff8f8;
}
.catalog-mobile-metrics {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    padding: 10px 0 0;
    border-top: 1px solid #f1f5f9;
}
.catalog-mobile-metrics > div {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 12px;
}
.catalog-mobile-metrics strong {
    font-size: 13px;
    color: #0f172a;
}

#filterForm input[type="number"]::-webkit-inner-spin-button,
#filterForm input[type="number"]::-webkit-outer-spin-button {
    opacity: 0.5;
}

.form-control-sm, .form-select-sm {
    font-size: 0.875rem;
}

.sensitive-price-checkbox {
    background-color: #3aaeb2;
    cursor: pointer;
}

.sensitive-prices-group .form-check {
    margin-bottom: 8px;                         
}

.selected-price-info {
    font-size: 0.875rem;
    padding: 5px 0;
    border-top: 1px solid #e9ecef;
}

/* Result-first inventory teaser */
.catalog-inventory-teaser {
    padding: 0.65rem 0.85rem;
    border: 1px solid #d9ecec;
    border-radius: 10px;
    background: linear-gradient(180deg, #f4fbfb 0%, #ffffff 100%);
}

.site-preview-zoom {
    width: 100%;
    max-width: 260px;
    margin: 0 auto;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
    background: #f8fafc;
    aspect-ratio: 16 / 10;
}
.site-preview-zoom img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center top;
    display: block;
    transition: transform .45s cubic-bezier(.22, 1, .36, 1);
    will-change: transform;
}
.site-preview-zoom:hover img {
    transform: scale(1.12);
}
.site-preview-zoom.is-broken { display: none; }
.site-preview-zoom.is-broken + .site-preview-fallback { display: inline-flex !important; }
.site-preview-fallback {
    width: 180px;
    height: 120px;
    margin: 0 auto;
}
.site-trust-compact {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    font-size: 11px;
    line-height: 1.3;
    color: #64748b;
}
.site-trust-compact__stars {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    color: #f59e0b;
    font-size: 11px;
}
.site-trust-compact__stars .fa-regular { color: #cbd5e1; }
.site-trust-compact__score {
    margin-left: 4px;
    color: #475569;
    font-weight: 600;
}
.site-trust-compact__sep { color: #cbd5e1; }
.site-trust-compact__orders {
    color: #0b6266;
    font-weight: 600;
}

.catalog-bulk-section .bulk-deal-card {
    border: 1px solid #d9ecec;
    border-radius: 12px;
    padding: 12px;
    background: linear-gradient(180deg, #f4fbfb 0%, #fff 100%);
}
.site-chip--featured {
    background: #fff7e6;
    color: #b45309;
    border: 1px solid #fde68a;
}
.site-chip--sale {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.site-chip--bulk {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

/* Results toolbar + empty recovery */
.catalog-results-bar {
    padding: 0.15rem 0.1rem;
}
.catalog-empty-state {
    max-width: 420px;
    padding: 0.5rem 1rem 1rem;
}
.catalog-empty-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 0.85rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--brand-primary-bg, #e8f8f7);
    color: var(--brand-primary, #0b6266);
    font-size: 1.25rem;
}

/* Sticky Buy column while browsing wide tables */
.catalog-table-scroll {
    overflow-x: auto;
}
.catalog-th-action,
.catalog-td-action {
    position: sticky;
    right: 0;
    z-index: 2;
    background: #fff;
    box-shadow: -8px 0 12px -10px rgba(15, 23, 42, 0.28);
}
.catalog-th-action {
    z-index: 3;
    background: #f8fafc;
}

/* Site column — status chips (Verified / New / Blacklisted) */
.catalog-site-cell {
    position: relative;
}

.catalog-site-stack {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.catalog-site-url {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-weight: 600;
    font-size: 13.5px;
}

.catalog-site-meta {
    font-size: 12.5px;
}

/* Compact NEW pill — brand teal, gentle pulse (beside site title) */
.site-badge-new {
    position: static;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 20px;
    padding: 0 8px;
    border: 0;
    border-radius: 999px;
    background: var(--brand-primary, #0b6266);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    line-height: 1;
    cursor: help;
    user-select: none;
    outline: none;
    box-shadow: 0 1px 3px rgba(11, 98, 102, 0.22);
    animation: siteNewPulse 2s ease-in-out infinite;
    flex-shrink: 0;
}

.site-badge-new:hover,
.site-badge-new:focus-visible,
.site-badge-new.is-open {
    background: var(--brand-primary-soft, #3aaeb2);
}

.site-badge-new:focus-visible {
    box-shadow: 0 0 0 3px rgba(58, 174, 178, 0.28);
}

@keyframes siteNewPulse {
    0%, 100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.06);
        opacity: 0.82;
    }
}

.site-status-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
}

.site-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    height: 22px;
    padding: 0 9px;
    border: 1px solid transparent;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.01em;
    line-height: 1;
    white-space: nowrap;
    transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
    cursor: help;
    user-select: none;
    background: transparent;
}

button.site-chip {
    margin: 0;
    font-family: inherit;
}

.site-chip i {
    font-size: 11px;
    line-height: 1;
}

.site-chip:hover {
    transform: translateY(-1px);
}

.site-chip--verified {
    background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%);
    color: #0f766e;
    border-color: rgba(15, 118, 110, 0.22);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.site-chip--verified:hover,
.site-chip--verified:focus-visible {
    box-shadow: 0 2px 8px rgba(15, 118, 110, 0.14);
}

.site-chip--verified i {
    color: #0d9488;
}

.site-chip--blacklist {
    background: linear-gradient(180deg, #fff5f5 0%, #fee2e2 100%);
    color: #b91c1c;
    border-color: rgba(220, 38, 38, 0.22);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.site-chip--blacklist:hover {
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.12);
}

@media (prefers-reduced-motion: reduce) {
    .site-badge-new {
        animation: none !important;
    }
}

/* Legacy pulse-dot kept inert if referenced elsewhere */
.pulse-dot {
    width: 6px;
    height: 6px;
    background-color: currentColor;
    border-radius: 50%;
    position: relative;
    display: none;
}

/* Outer pulse ring */
.pulse-dot::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(255,255,255,0.6);
    animation: pulse-ring 1.6s infinite ease-out;
}

/* Category */
.category-tile {
    transition: all 0.3s ease;
}

.btn-toggle-categories {
    transition: all 0.2s ease;
}

.btn-toggle-categories:hover {
    text-decoration: underline !important;
}

/* For the grid version */
.categories-grid {
    transition: all 0.3s ease;
}

.category-item {
    transition: all 0.2s ease;
}


.no-spinner::-webkit-inner-spin-button,
.no-spinner::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.no-spinner {
    -moz-appearance: textfield;
}

/* Multi-select Styles - Matching Bootstrap exactly */
.multi-select-wrapper {
    position: relative;
    width: 100%;
}

.multi-select-input {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    cursor: pointer !important;
    padding-right: 2.25rem !important;
}

.multi-select-input .selected-items {
    flex: 1;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
    min-height: 23px;
}

.multi-select-input .placeholder-text {
    color: #6c757d;
    font-size: 0.875rem;
}

.selected-tag {
    background-color: #3aaeb2;
    color: #495057;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 0.75rem;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.selected-tag .remove-tag {
    cursor: pointer;
    font-weight: bold;
    margin-left: 4px;
    color: #6c757d;
}

.selected-tag .remove-tag:hover {
    color: #dc3545;
}

.multi-select-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ced4da;
    border-radius: 0.2rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 1000;
    max-height: 280px;
    overflow-y: auto;
    display: none;
    margin-top: 4px;
}

.multi-select-dropdown.show {
    display: block;
}

.multi-select-dropdown .search-box {
    padding: 8px 10px;
    border-bottom: 1px solid #dee2e6;
    position: sticky;
    top: 0;
    background: white;
    z-index: 1;
}

.multi-select-dropdown .search-box i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #3aaeb2;
    font-size: 12px;
    z-index: 2;
}

.multi-select-dropdown .search-box input {
    padding-left: 28px;
    
}

.options-list {
    max-height: 220px;
    overflow-y: auto;
}

.option-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    cursor: pointer;
    transition: background 0.15s ease;
    margin: 0;
}

.option-item:hover {
    background-color: #3aaeb2;
}

.option-item input[type="checkbox"] {
    margin: 0;
    cursor: pointer;
    width: 14px;
    height: 14px;
}

.option-item span {
    font-size: 0.875rem;
    color: #212529;
    cursor: pointer;
}

/* Ensure chevron icon is positioned correctly */
.multi-select-input i {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    font-size: 0.75rem;
    color: #6c757d;
}

.categories-column {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.category-badge {
    background: #e8f8f7;
    color: #0b6266;
    border: 1px solid #b8e8e6;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 10px;
    font-weight: 500;
    line-height: 1.2;
    width: fit-content;
    max-width: 100%;
}

.toggle-cats-btn {
    background: none;
    border: none;
    color: #0b6266;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    padding: 4px 0 0;
    text-align: left;
}

.toggle-cats-btn:hover {
    text-decoration: underline;
}


/* Update the CSS styles - remove border colors from normal state, add on hover/focus */

/* Only show borders on hover/focus for form controls */
.form-control, 
.form-select,
.multi-select-input {
    border-color: #ced4da;
    transition: all 0.2s ease;
}

.form-control:hover, 
.form-select:hover,
.multi-select-input:hover {
    border-color: #3aaeb2;
}

.form-control:focus, 
.form-select:focus,
.multi-select-input:focus-within {
    border-color: #3aaeb2 !important;
    box-shadow: 0 0 0 0.2rem rgba(58, 174, 178, 0.25) !important;
}

/* Multi-select dropdown styles */
.option-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    margin: 0;
}

.option-item:hover {
    background-color: #3aaeb2 !important;
}

.option-item:hover span {
    color: white !important;
}

/* Checkbox styling */
.option-item input[type="checkbox"] {
    accent-color: #3aaeb2;
    cursor: pointer;
    width: 14px;
    height: 14px;
}

.form-check-input:checked {
    background-color: #3aaeb2 !important;
    border-color: #3aaeb2 !important;
}

.form-check-input:focus {
    border-color: #3aaeb2 !important;
    box-shadow: 0 0 0 0.2rem rgba(58, 174, 178, 0.25) !important;
}

/* Selected tags */
.selected-tag {
    background-color: #3aaeb2;
    color: white;
}

.selected-tag .remove-tag {
    color: white;
}

.selected-tag .remove-tag:hover {
    color: #ffcccc;
}

/* Dropdown chevron icon */
.multi-select-input i {
    color: #6c757d;
}

.multi-select-input:hover i {
    color: #3aaeb2;
}

/* Search icon in dropdown */
.multi-select-dropdown .search-box i {
    color: #3aaeb2;
}

/* Sponsored, Favorites, Blacklist select dropdown options background - Updated */
select.form-select option:hover,
select.form-select option:focus,
select.form-select option:active {
    background-color: #3aaeb2 !important;
    color: white !important;
}

/* Style for select dropdown when focused */
select.form-select:focus {
    border-color: #3aaeb2 !important;
    box-shadow: 0 0 0 0.2rem rgba(58, 174, 178, 0.25) !important;
}

/* For the select options background in different browsers */
select.form-select option {
    transition: background-color 0.2s ease;
    background-color: white;
    color: #212529;
}

/* Chrome/Safari/Edge - selected and hover states */
select.form-select option:checked,
select.form-select option:hover {
    background: #3aaeb2 !important;
    background-color: #3aaeb2 !important;
    color: white !important;
}

/* Firefox specific */
@-moz-document url-prefix() {
    select.form-select option:checked,
    select.form-select option:hover {
        background-color: #3aaeb2 !important;
        color: white !important;
    }
}

/* For when the select is opened and options are hovered */
select.form-select[size] option:hover,
select.form-select[multiple] option:hover {
    background-color: #3aaeb2 !important;
    color: white !important;
}

/* Style the select dropdown arrow on hover */
select.form-select:hover {
    border-color: #3aaeb2;
    cursor: pointer;
}

/* Custom dropdown styling for better hover effect */
select.form-select {
    cursor: pointer;
    transition: all 0.2s ease;
}

select.form-select option {
    padding: 8px 12px;
    cursor: pointer;
}

/* Style the dropdown when opened */
select.form-select:focus option:hover {
    background: #3aaeb2 linear-gradient(0deg, #3aaeb2 0%, #3aaeb2 100%);
    color: white;
}

/* Additional style for the select element hover state */
select.form-select:focus option:checked {
    background: #3aaeb2 linear-gradient(0deg, #3aaeb2 0%, #3aaeb2 100%);
    color: white;
}

/* Animation */
@keyframes pulse-ring {
    0% {
        transform: scale(1);
        opacity: 0.8;
    }
    70% {
        transform: scale(2.5);
        opacity: 0;
    }
    100% {
        opacity: 0;
    }
}
</style>

<script>
// Initialize favorites and blacklist from database
let favorites = @json($favorites);
let blacklist = @json($blacklist);

// Multi-select variables
let selectedMultiFilters = {
    category: [],
    country: [],
    language: []
};

// Initialize from URL parameters
@php
    $categoryParam = request('category', '');
    $countryParam = request('country', '');
    $languageParam = request('language', '');
@endphp

if ('{{ $categoryParam }}') {
    selectedMultiFilters.category = '{{ $categoryParam }}'.split(',').filter(function(v) { return v; });
}
if ('{{ $countryParam }}') {
    selectedMultiFilters.country = '{{ $countryParam }}'.split(',').filter(function(v) { return v; });
}
if ('{{ $languageParam }}') {
    selectedMultiFilters.language = '{{ $languageParam }}'.split(',').filter(function(v) { return v; });
}

function closeAllMultiDropdowns(exceptId) {
    var dropdowns = document.querySelectorAll('.multi-select-dropdown');
    for (var i = 0; i < dropdowns.length; i++) {
        if (exceptId && dropdowns[i].id === exceptId) continue;
        dropdowns[i].classList.remove('show');
        var otherTrigger = dropdowns[i].previousElementSibling;
        if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
    }
}

function getVisibleMultiOptions(dropdown) {
    return Array.prototype.slice.call(dropdown.querySelectorAll('.option-item')).filter(function (el) {
        return el.style.display !== 'none';
    });
}

function focusMultiOption(dropdown, index) {
    var options = getVisibleMultiOptions(dropdown);
    if (!options.length) return;
    var i = ((index % options.length) + options.length) % options.length;
    options.forEach(function (el) { el.classList.remove('is-keyboard-focus'); });
    options[i].classList.add('is-keyboard-focus');
    var input = options[i].querySelector('input');
    if (input) input.focus({ preventScroll: false });
    options[i].scrollIntoView({ block: 'nearest' });
    dropdown.dataset.focusIndex = String(i);
}

function toggleMultiDropdown(dropdownId, triggerEl) {
    if (typeof event !== 'undefined' && event) event.stopPropagation();
    closeAllMultiDropdowns(dropdownId);
    var dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    var willOpen = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willOpen);
    if (triggerEl) triggerEl.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) {
        var searchInput = dropdown.querySelector('.search-box input');
        if (searchInput) {
            searchInput.value = '';
            var list = dropdown.querySelector('.options-list');
            if (list) filterMultiOptions(list.id, '');
            setTimeout(function () { searchInput.focus(); }, 10);
        }
        dropdown.dataset.focusIndex = '-1';
    }
}

document.addEventListener('keydown', function (e) {
    var openDropdown = document.querySelector('.multi-select-dropdown.show');
    var trigger = e.target.closest && e.target.closest('.multi-select-input');

    if (trigger && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) {
        e.preventDefault();
        var wrapper = trigger.closest('.multi-select-wrapper');
        var dropdown = wrapper ? wrapper.querySelector('.multi-select-dropdown') : null;
        if (dropdown) toggleMultiDropdown(dropdown.id, trigger);
        return;
    }

    if (!openDropdown) return;

    if (e.key === 'Escape') {
        e.preventDefault();
        openDropdown.classList.remove('show');
        var openTrigger = openDropdown.previousElementSibling;
        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
            openTrigger.focus();
        }
        return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var current = parseInt(openDropdown.dataset.focusIndex || '-1', 10);
        focusMultiOption(openDropdown, e.key === 'ArrowDown' ? current + 1 : current - 1);
        return;
    }

    if (e.key === 'Enter' && e.target && e.target.matches && e.target.matches('.option-item input, .option-item')) {
        // native checkbox toggle via Enter on focused input
        return;
    }
});

function filterMultiOptions(optionsId, searchTerm) {
    var options = document.getElementById(optionsId);
    if (!options) return;
    var optionItems = options.querySelectorAll('.option-item');
    var term = (searchTerm || '').toLowerCase().trim();
    var visible = 0;

    for (var i = 0; i < optionItems.length; i++) {
        var option = optionItems[i];
        var text = (option.querySelector('span') ? option.querySelector('span').textContent : '').toLowerCase();
        var code = (option.querySelector('input') ? option.querySelector('input').value : '').toLowerCase();
        var match = term === '' || text.indexOf(term) !== -1 || code.indexOf(term) !== -1;
        option.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    }

    var empty = options.parentElement ? options.parentElement.querySelector('.multi-select-empty') : null;
    if (empty) empty.classList.toggle('d-none', visible > 0);
}

function updateMultiFilter(checkbox) {
    var type = checkbox.getAttribute('data-type');
    var value = checkbox.value;
    
    if (checkbox.checked) {
        if (selectedMultiFilters[type].indexOf(value) === -1) {
            selectedMultiFilters[type].push(value);
        }
    } else {
        var newArray = [];
        for (var i = 0; i < selectedMultiFilters[type].length; i++) {
            if (selectedMultiFilters[type][i] !== value) {
                newArray.push(selectedMultiFilters[type][i]);
            }
        }
        selectedMultiFilters[type] = newArray;
    }
    
    // Update display
    updateMultiDisplay(type);
}

function updateMultiDisplay(type) {
    var container = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1) + 'sDisplay');
    var values = selectedMultiFilters[type];
    
    if (!container) return;
    
    container.innerHTML = '';
    
    if (values.length === 0) {
        container.innerHTML = '<span class="placeholder-text">Select ' + type + 's...</span>';
        return;
    }
    
    for (var i = 0; i < values.length; i++) {
        var value = values[i];
        var displayName = value;
        
        if (type === 'country') {
            var option = document.querySelector('#countryMultiOptions input[value="' + value + '"]');
            if (option) {
                displayName = option.getAttribute('data-name') || value;
            }
        }
        
        if (type === 'language') {
            var option = document.querySelector('#languageMultiOptions input[value="' + value + '"]');
            if (option) {
                displayName = option.getAttribute('data-name') || value;
            }
        }
        
        var tag = document.createElement('span');
        tag.className = 'selected-tag';
        tag.innerHTML = displayName + ' <span class="remove-tag" onclick="event.stopPropagation(); removeMultiFilter(\'' + type + '\', \'' + value + '\')">&times;</span>';
        container.appendChild(tag);
    }
}

function removeMultiFilter(type, value) {
    var newArray = [];
    for (var i = 0; i < selectedMultiFilters[type].length; i++) {
        if (selectedMultiFilters[type][i] !== value) {
            newArray.push(selectedMultiFilters[type][i]);
        }
    }
    selectedMultiFilters[type] = newArray;
    
    var checkbox = document.querySelector('#' + type + 'MultiOptions input[value="' + value + '"]');
    if (checkbox) {
        checkbox.checked = false;
    }
    
    updateMultiDisplay(type);
}

function initializeMultiSelects() {
    // Initialize checkboxes
    for (var i = 0; i < selectedMultiFilters.category.length; i++) {
        var value = selectedMultiFilters.category[i];
        var checkbox = document.querySelector('#categoryMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    for (var i = 0; i < selectedMultiFilters.country.length; i++) {
        var value = selectedMultiFilters.country[i];
        var checkbox = document.querySelector('#countryMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    for (var i = 0; i < selectedMultiFilters.language.length; i++) {
        var value = selectedMultiFilters.language[i];
        var checkbox = document.querySelector('#languageMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    // Update displays
    updateMultiDisplay('category');
    updateMultiDisplay('country');
    updateMultiDisplay('language');
}

function submitCatalogFilters() {
    document.getElementById('selectedCategory').value = selectedMultiFilters.category.join(',');
    document.getElementById('selectedCountry').value = selectedMultiFilters.country.join(',');
    document.getElementById('selectedLanguage').value = selectedMultiFilters.language.join(',');
    document.getElementById('filterForm').submit();
}

// Apply Filters button - submit the form with all selected values
document.getElementById('applyFiltersBtn').addEventListener('click', function() {
    submitCatalogFilters();
});

// Favorites / Blacklist selects apply immediately so heart & block workflows are obvious
['favorites_filter', 'blacklist_filter'].forEach(function (name) {
    const select = document.querySelector('select[name="' + name + '"]');
    if (!select) return;
    select.addEventListener('change', function () {
        submitCatalogFilters();
    });
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.multi-select-wrapper')) {
        var dropdowns = document.querySelectorAll('.multi-select-dropdown');
        for (var i = 0; i < dropdowns.length; i++) {
            if (dropdowns[i]) {
                dropdowns[i].classList.remove('show');
            }
        }
    }
});

// Initialize multi-selects on page load
initializeMultiSelects();

// Store selected sensitive price additional amount for each site
let selectedSensitiveAdditionalPrice = {};

// Toast function
function showToast(message, type = 'success') {
    const toastEl = document.getElementById('toastMessage');
    if (toastEl) {
        const toastBody = document.getElementById('toastMessageBody');
        toastBody.innerText = message;
        
        if (type === 'success') {
            toastEl.classList.remove('bg-danger', 'bg-warning');
            toastEl.classList.add('bg-success');
        } else if (type === 'error') {
            toastEl.classList.remove('bg-success', 'bg-warning');
            toastEl.classList.add('bg-danger');
        } else if (type === 'warning') {
            toastEl.classList.remove('bg-success', 'bg-danger');
            toastEl.classList.add('bg-warning');
        }
        
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
    } else {
        alert(message);
    }
}

// Update cart badge
function updateCartBadge() {
    if (typeof window.updateCartBadge === 'function') {
        window.updateCartBadge();
    }
}

// Add to cart
function addToCart(id, name, basePrice, additionalPrice = 0) {
    let finalPrice = parseFloat(basePrice) + parseFloat(additionalPrice);
    
    if (typeof window.addToCart === 'function') {
        window.addToCart(id, name, finalPrice);
    } else {
        window.location.reload();
    }
    
    return finalPrice;
}

// Update UI for favorites and blacklist (quiet icon actions)
function updateButtonStates() {
    document.querySelectorAll('.favorite-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        const icon = btn.querySelector('i');
        if (favorites.includes(id)) {
            btn.classList.add('is-active');
            if (icon) { icon.classList.remove('fa-regular'); icon.classList.add('fa-solid'); }
            btn.title = 'Remove from Favorites';
            btn.setAttribute('aria-label', 'Remove from favorites');
        } else {
            btn.classList.remove('is-active');
            if (icon) { icon.classList.remove('fa-solid'); icon.classList.add('fa-regular'); }
            btn.title = 'Add to Favorites';
            btn.setAttribute('aria-label', 'Add to favorites');
        }
    });

    document.querySelectorAll('.blacklist-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        if (blacklist.includes(id)) {
            btn.classList.add('is-active');
            btn.title = 'Remove from Blacklist';
            btn.setAttribute('aria-label', 'Remove from blacklist');
        } else {
            btn.classList.remove('is-active');
            btn.title = 'Blacklist Site';
            btn.setAttribute('aria-label', 'Blacklist site');
        }
        btn.style.backgroundColor = '';
        btn.style.color = '';
    });
}

// Update buy button price display
function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0) {
    document.querySelectorAll(`.buy-now[data-id="${siteId}"]`).forEach(function (buyButton) {
        let priceSpan = buyButton.querySelector('.base-price-display, .fw-semibold');
        let totalPrice = parseFloat(basePrice) + parseFloat(additionalPrice);
        if (priceSpan) {
            priceSpan.textContent = `€${totalPrice.toFixed(2)}`;
        }
        buyButton.dataset.currentAdditionalPrice = additionalPrice;
    });
}

// Save favorites to database
function saveFavorites() {
    return fetch('{{ route("advertiser.favorites.save") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ favorites: favorites })
    }).then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Could not save favorites');
        }
        return data;
    }).catch(err => {
        console.error('Error saving favorites:', err);
        showToast(err.message || 'Could not save favorites', 'error');
    });
}

// Save blacklist to database
function saveBlacklist() {
    return fetch('{{ route("advertiser.blacklist.save") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ blacklist: blacklist })
    }).then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Could not save blacklist');
        }
        return data;
    }).catch(err => {
        console.error('Error saving blacklist:', err);
        showToast(err.message || 'Could not save blacklist', 'error');
    });
}

function hideCatalogSite(siteId) {
    document.querySelectorAll(`.site-row[data-id="${siteId}"], .catalog-mobile-card[data-id="${siteId}"]`).forEach((el) => {
        el.style.transition = 'opacity 0.3s ease';
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; }, 300);
    });
    const expandedRow = document.querySelector('.expanded-row-' + siteId);
    if (expandedRow) {
        expandedRow.style.transition = 'opacity 0.3s ease';
        expandedRow.style.opacity = '0';
        setTimeout(() => { expandedRow.style.display = 'none'; }, 300);
    }
}

function showCatalogSite(siteId) {
    document.querySelectorAll(`.site-row[data-id="${siteId}"], .catalog-mobile-card[data-id="${siteId}"]`).forEach((el) => {
        el.style.display = '';
        el.style.opacity = '';
        el.style.transition = '';
        el.classList.remove('blacklisted-row', 'is-blacklisted');
    });
    const expandedRow = document.querySelector('.expanded-row-' + siteId);
    if (expandedRow) {
        expandedRow.style.display = '';
        expandedRow.style.opacity = '';
        expandedRow.style.transition = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateButtonStates();

    // Store selected sensitive price for each site
    let selectedSensitivePrices = {};

    // Handle sensitive price checkbox selection
    document.querySelectorAll('.sensitive-prices-group').forEach(group => {
        let siteId = group.dataset.siteId;
        let basePrice = parseFloat(group.dataset.basePrice);
        let checkboxes = group.querySelectorAll('.sensitive-price-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function(e) {
                e.stopPropagation();
                
                if (!this.checked) return;

                let additionalPrice = parseFloat(this.dataset.additionalPrice);
                let totalPrice = parseFloat(this.dataset.totalPrice);
                let priceType = this.dataset.type;

                if (priceType === 'none' || additionalPrice === 0) {
                    delete selectedSensitivePrices[siteId];
                    updateBuyButtonPrice(siteId, basePrice, 0);
                    let priceInfoDiv = document.getElementById(`price-info-${siteId}`);
                    if (priceInfoDiv) {
                        priceInfoDiv.innerHTML = `
                            <small class="text-muted">Current price: <strong>€${basePrice.toFixed(2)}</strong> (Base price)</small>
                        `;
                    }
                    return;
                }

                selectedSensitivePrices[siteId] = {
                    type: priceType,
                    additionalPrice: additionalPrice,
                    totalPrice: totalPrice
                };

                updateBuyButtonPrice(siteId, basePrice, additionalPrice);

                let priceInfoDiv = document.getElementById(`price-info-${siteId}`);
                if (priceInfoDiv) {
                    priceInfoDiv.innerHTML = `
                        <small class="text-muted">Base price: <strong>€${basePrice.toFixed(2)}</strong></small><br>
                        <small class="text-success">Selected: <strong>${priceType}</strong> - Total: <strong>€${totalPrice.toFixed(2)}</strong> (+€${additionalPrice.toFixed(2)})</small>
                    `;
                }

                showToast(`${priceType} selected: +€${additionalPrice.toFixed(2)} - Total: €${totalPrice.toFixed(2)}`, 'success');
            });
        });
    });

    // Toggle URL visibility (desktop table + mobile cards)
    document.querySelectorAll('.toggle-url').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = this.dataset.id;
            let prefix = this.dataset.urlPrefix ? this.dataset.urlPrefix + '-' : '';
            let maskedSpan = document.getElementById('url-masked-' + prefix + id);
            let fullSpan = document.getElementById('url-full-' + prefix + id);
            if (!maskedSpan || !fullSpan) return;

            if (maskedSpan.classList.contains('d-none')) {
                maskedSpan.classList.remove('d-none');
                fullSpan.classList.add('d-none');
                this.querySelector('i').classList.remove('fa-eye-slash');
                this.querySelector('i').classList.add('fa-eye');
                this.setAttribute('aria-label', 'Reveal full URL');
            } else {
                maskedSpan.classList.add('d-none');
                fullSpan.classList.remove('d-none');
                this.querySelector('i').classList.remove('fa-eye');
                this.querySelector('i').classList.add('fa-eye-slash');
                this.setAttribute('aria-label', 'Hide full URL');
            }
        });
    });

    // Toggle expanded row
    function toggleExpandRow(id, arrowElement) {
        let expandedRow = document.querySelector('.expanded-row-' + id);
        
        if (expandedRow.style.display === 'none' || expandedRow.style.display === '') {
            document.querySelectorAll('[class^="expanded-row-"]').forEach(row => {
                if (row.style.display === 'table-row') {
                    row.style.display = 'none';
                    let rowId = row.className.match(/expanded-row-(\d+)/);
                    if (rowId && rowId[1]) {
                        let otherArrow = document.getElementById('arrow-' + rowId[1]);
                        if (otherArrow) {
                            otherArrow.classList.remove('rotate-arrow');
                        }
                    }
                }
            });
            
            expandedRow.style.display = 'table-row';
            if (arrowElement) {
                arrowElement.classList.add('rotate-arrow');
                arrowElement.setAttribute('aria-expanded', 'true');
            }
        } else {
            expandedRow.style.display = 'none';
            if (arrowElement) {
                arrowElement.classList.remove('rotate-arrow');
                arrowElement.setAttribute('aria-expanded', 'false');
            }
        }
    }

    document.querySelectorAll('.site-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if(e.target.closest('.toggle-url') || e.target.closest('.buy-now') || 
               e.target.closest('.favorite-btn') || e.target.closest('.blacklist-btn') ||
               e.target.closest('.copy-example-url') || e.target.closest('.expand-arrow') ||
               e.target.closest('.sensitive-price-checkbox') || e.target.closest('a') ||
               e.target.closest('.form-check-label')) {
                return;
            }
            
            let id = this.dataset.id;
            let arrowElement = document.getElementById('arrow-' + id);
            toggleExpandRow(id, arrowElement);
        });
    });

    document.querySelectorAll('.expand-arrow').forEach(arrow => {
        arrow.addEventListener('click', function(e) {
            e.stopPropagation();
            let id = this.id.replace('arrow-', '');
            toggleExpandRow(id, this);
        });
    });

    // Copy example URL
    document.querySelectorAll('.copy-example-url').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            let url = this.dataset.url;
            
            try {
                await navigator.clipboard.writeText(url);
                showToast('URL copied to clipboard!', 'success');
                let originalText = this.innerHTML;
                this.innerHTML = '<i class="fa-regular fa-check"></i> Copied!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 1500);
            } catch (err) {
                console.error('Failed to copy:', err);
                showToast('Failed to copy URL', 'error');
            }
        });
    });

    // Add to Cart
    document.querySelectorAll('.buy-now').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let basePrice = parseFloat(this.dataset.basePrice);
            let name = this.dataset.name;
            
            let sensitiveType = selectedSensitivePrices[id] ? selectedSensitivePrices[id].type : null;
            let additionalPrice = selectedSensitivePrices[id] ? selectedSensitivePrices[id].additionalPrice : 0;
            let finalPrice = basePrice + additionalPrice;
            
            if (typeof window.addToCart === 'function') {
                window.addToCart(id, name, finalPrice, sensitiveType, additionalPrice, basePrice);
            }
            
            let originalText = this.innerHTML;
            this.innerHTML = '<i class="fa-solid fa-check"></i> Added!';
            setTimeout(() => {
                this.innerHTML = originalText;
            }, 1000);
        });
    });

    // Favorite functionality (desktop table + mobile cards stay in sync)
    document.querySelectorAll('.favorite-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = favorites.indexOf(id);

            if (index === -1) {
                favorites.push(id);
                showToast(`${name} added to favorites!`, 'success');
            } else {
                favorites.splice(index, 1);
                showToast(`${name} removed from favorites!`, 'warning');
                // On Favorites Only view, remove the site from the list immediately
                @if(request('favorites_filter') == '1')
                hideCatalogSite(id);
                @endif
            }

            updateButtonStates();
            saveFavorites();
        });
    });

    // Blacklist functionality — hide from catalog; show again under Blacklisted Only / after unblock
    document.querySelectorAll('.blacklist-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = blacklist.indexOf(id);

            if (index === -1) {
                blacklist.push(id);
                showToast(`${name} has been blacklisted!`, 'warning');
                // Main catalog: remove immediately (desktop row + mobile card)
                @if(!request('blacklist_filter'))
                hideCatalogSite(id);
                @endif
            } else {
                blacklist.splice(index, 1);
                showToast(`${name} removed from blacklist!`, 'success');
                @if(request('blacklist_filter') == '1')
                // Blacklisted Only view: site no longer belongs here
                hideCatalogSite(id);
                @else
                showCatalogSite(id);
                @endif
            }

            updateButtonStates();
            saveBlacklist();
        });
    });
});

// Safety net: hide any blacklisted sites still rendered on the main catalog
@if(!request('blacklist_filter'))
document.querySelectorAll('.site-row[data-id], .catalog-mobile-card[data-id]').forEach(el => {
    let id = parseInt(el.dataset.id);
    if (blacklist.includes(id)) {
        hideCatalogSite(id);
    }
});
@endif
</script>

<script>
document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-suggest-website');
    if (!btn) return;
    const prefill = btn.dataset.search || document.querySelector('input[name="search"]')?.value || '';
    const { value: form } = await Swal.fire({
        title: 'Suggest a website',
        html: `<p class="small text-muted mb-2">Can’t find a publisher site? Suggest it and we’ll try to include it.</p>
               <input id="swal-site-name" class="swal2-input" placeholder="Website name" value="${prefill.replace(/"/g, '&quot;')}">
               <input id="swal-site-url" class="swal2-input" placeholder="https://example.com">
               <textarea id="swal-site-notes" class="swal2-textarea" placeholder="Why should we add it? (optional)"></textarea>`,
        showCancelButton: true,
        confirmButtonText: 'Submit suggestion',
        confirmButtonColor: '#0b6266',
        preConfirm: () => {
            const website_name = document.getElementById('swal-site-name').value.trim();
            const website_url = document.getElementById('swal-site-url').value.trim();
            const notes = document.getElementById('swal-site-notes').value.trim();
            if (!website_name || !website_url) {
                Swal.showValidationMessage('Website name and URL are required');
                return false;
            }
            return { website_name, website_url, notes, search_query: prefill };
        },
    });
    if (!form) return;
    const res = await fetch(`{{ route('advertiser.website-suggestions.store') }}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(form),
    });
    const data = await res.json().catch(() => ({}));
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
});
</script>

@endsection