@extends('advertiser.layouts.app')

@push('page-styles')
    <link href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: '1' }}" rel="stylesheet">
    {{-- Critical catalog guards: if a stale CDN copy of catalog.css wins the
         race, Chrome still must not paint a stuck busy layer, teal NEW pills,
         or intrinsic-size metric logos. --}}
    <style id="catalog-critical">
        .catalog-results-busy[hidden]{display:none!important}
        .catalog-page button.site-badge-new,
        .catalog-page .site-badge-new{background:#ef4444!important;background-image:none!important;color:#fff!important;border:0!important}
        .catalog-page .metric-source{width:var(--metric-source-size,20px);height:var(--metric-source-size,20px);max-width:var(--metric-source-size,20px);max-height:var(--metric-source-size,20px);overflow:hidden}
        .catalog-page .metric-source img{width:var(--metric-source-size,20px);height:var(--metric-source-size,20px);max-width:var(--metric-source-size,20px);max-height:var(--metric-source-size,20px)}
        .catalog-page .categories-column{display:flex;flex-direction:row;flex-wrap:wrap;justify-content:center;align-items:center;gap:4px}
        /* Metric bars must paint even if a stale CDN copy of catalog.css wins. */
        .catalog-page .catalog-metric{display:inline-flex;flex-direction:column;align-items:center;gap:4px;min-width:3.5rem}
        .catalog-page .catalog-metric__bar{display:block!important;width:3.25rem;max-width:100%;height:6px;border-radius:999px;background:#d5dbe3;overflow:hidden}
        .catalog-page .catalog-metric__fill{display:block!important;height:100%;border-radius:inherit;background:#3faeb2}
        .catalog-page .catalog-metric--da .catalog-metric__fill{background:#24abe2}
        .catalog-page .catalog-country{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px}
        .catalog-page .catalog-country__flag{font-size:22px;line-height:1}
        .catalog-page .catalog-table tbody td,
        .catalog-page .catalog-table tbody td.catalog-stat-cell{vertical-align:middle}
    </style>
@endpush

@section('content')

@php
    use Illuminate\Support\Str;
    $sites = $sites ?? collect();
    $favorites = $favorites ?? [];
    $blacklist = $blacklist ?? [];

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

{{-- catalog-page scopes this page's stylesheet. Without it, rules for .table,
     .badge and .form-control reached the cart drawer and the shell chrome. --}}
<div class="container-fluid catalog-page">
    @include('components.ad-banners', ['placement' => 'marketplace', 'audience' => 'advertiser'])

    @if(request()->boolean('wizard') && ! empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language']))
        @include('advertiser.wizard._catalog_chrome')
    @elseif(!empty($orderingSubmission))
        @include('advertiser.partials.ordering-path', [
            'step' => 2,
            'title' => 'Place a guest post · Publishers',
            'subtitle' => 'Ordering “'.($orderingSubmission->title ?: $orderingSubmission->original_filename).'” ('
                .strtoupper((string) $orderingSubmission->language).'). Browse any sites — language does not have to match — then assign in your cart.',
            'linkAll' => true,
            'contentRoute' => route('advertiser.content-library'),
            'actions' => '<button type="button" class="btn btn-sm btn-primary" onclick="openCart()">Review cart</button>'
                .'<a href="'.e(route('advertiser.catalog', ['cancel_library_order' => 1])).'" class="btn btn-sm btn-outline-secondary">Cancel</a>'
                .'<a href="'.e(route('advertiser.content-library')).'" class="btn btn-sm btn-outline-secondary">Back to library</a>',
        ])
    @else
        @include('advertiser.partials.ordering-path', [
            'step' => 2,
            'title' => 'Catalog · Publishers',
            'subtitle' => 'One job here: pick publishers. Keep browsing with items in your cart — finish payment when ready. Prefer steps? Use Guided.',
            'linkAll' => true,
            'contentRoute' => route('advertiser.content-library'),
            'actions' => '<button type="button" class="btn btn-sm btn-outline-primary" onclick="openCart()">Open cart</button>'
                .'<a href="'.e(route('advertiser.wizard.start')).'" class="btn btn-sm btn-outline-secondary">Guided</a>',
        ])
    @endif

    @if(($approvedArticleCount ?? 0) === 0 && empty($orderingSubmission))
        <div class="alert alert-info border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small mb-0">
                Each website needs its own <strong>approved</strong> article. You can still add publishers —
                readiness chips show what’s missing, and the cart checklist walks you through assignment.
            </div>
            <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-sm btn-upload">
                <i class="fa fa-upload me-1" aria-hidden="true"></i> Upload article
            </a>
        </div>
    @endif

    @php
        $catalogCart = session('cart', []);
        $catalogCartCount = (int) array_sum(array_map(fn ($row) => (int) ($row['quantity'] ?? 0), is_array($catalogCart) ? $catalogCart : []));
    @endphp
    @if($catalogCartCount > 0)
        <div class="alert alert-light border shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="small mb-0">
                You have <strong>{{ $catalogCartCount }}</strong> {{ Str::plural('site', $catalogCartCount) }} in your cart.
                Keep browsing anytime — open the cart when you are ready to assign articles and pay.
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openCart()">
                <i class="fa fa-shopping-cart me-1" aria-hidden="true"></i> Open cart
            </button>
        </div>
    @endif

    @if(($catalogBonusBalance ?? 0) > 0)
        <p class="small text-muted mb-3">
            Spendable <strong>€{{ number_format((float) ($catalogSpendableBalance ?? 0), 2) }}</strong>
            (cash €{{ number_format((float) ($catalogCashBalance ?? 0), 2) }}
            + bonus €{{ number_format((float) $catalogBonusBalance, 2) }}).
            Apply bonus at checkout.
        </p>
    @endif

    <!-- HEADER -->
    <div class="row mb-3">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Catalog</h2>
            <p class="text-muted mb-0">
                @if(!empty($orderingSubmission))
                    Browse any verified publishers for “{{ $orderingSubmission->title ?: $orderingSubmission->original_filename }}”. Filters stay optional — language does not have to match.
                @else
                    Browse verified publishers and add sites to your cart.
                @endif
            </p>
        </div>
    </div>

    <!-- FILTERS SECTION -->
@php
    $moreFilterKeys = ['sponsored','favorites_filter','blacklist_filter','da_min','da_max','dr_min','dr_max','traffic_min','traffic_max','new_badge'];
    $moreFiltersOpen = collect($moreFilterKeys)->contains(fn ($k) => filled(request($k)));
    // Each chip carries the query keys it owns so it can be dismissed on its own.
    // Range filters span two inputs, so one chip clears both ends.
    $activeFilterChips = [];
    if (request('site')) $activeFilterChips[] = ['label' => 'Recommended site', 'key' => 'site', 'params' => ['site']];
    if (request('search')) $activeFilterChips[] = ['label' => 'Search: '.request('search'), 'key' => 'search', 'params' => ['search']];
    if (request('category')) $activeFilterChips[] = ['label' => 'Category', 'key' => 'category', 'params' => ['category']];
    if (request('country')) $activeFilterChips[] = ['label' => 'Country', 'key' => 'country', 'params' => ['country']];
    if (request('price_min') || request('price_max')) $activeFilterChips[] = ['label' => 'Price', 'key' => 'price', 'params' => ['price_min', 'price_max']];
    if (request('language')) $activeFilterChips[] = ['label' => 'Language', 'key' => 'language', 'params' => ['language']];
    if (request('sponsored') == '1') $activeFilterChips[] = ['label' => 'Sponsored', 'key' => 'sponsored', 'params' => ['sponsored']];
    if (request('favorites_filter') == '1') $activeFilterChips[] = ['label' => 'Favorites', 'key' => 'favorites_filter', 'params' => ['favorites_filter']];
    if (request('blacklist_filter') == '1') $activeFilterChips[] = ['label' => 'Blacklist', 'key' => 'blacklist_filter', 'params' => ['blacklist_filter']];
    if (request('da_min') || request('da_max')) $activeFilterChips[] = ['label' => 'DA (Domain Authority)', 'key' => 'da', 'params' => ['da_min', 'da_max']];
    if (request('dr_min') || request('dr_max')) $activeFilterChips[] = ['label' => 'DR (Domain Rating)', 'key' => 'dr', 'params' => ['dr_min', 'dr_max']];
    if (request('traffic_min') || request('traffic_max')) $activeFilterChips[] = ['label' => 'Traffic', 'key' => 'traffic', 'params' => ['traffic_min', 'traffic_max']];
    if (request('new_badge') == '1') $activeFilterChips[] = ['label' => 'New sites', 'key' => 'new_badge', 'params' => ['new_badge']];
    $inventoryTotal = $sites->total();
    $inventoryFrom = $sites->getCollection()->min(fn ($s) => (float) $s->price);
    // An explicit filters_open wins, so "Hide filters" survives a submit.
    // Without one, the panel opens itself when filters are already narrowing.
    $filtersExpanded = request()->has('filters_open')
        ? request()->boolean('filters_open')
        : (count($activeFilterChips) > 0 || $moreFiltersOpen);
@endphp

{{-- Result-first teaser (CV2): inventory + price before heavy filter chrome --}}
<div class="catalog-inventory-teaser d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <div class="small">
        @if($inventoryTotal > 0)
            <strong class="text-dark">{{ number_format($inventoryTotal) }}</strong>
            {{ Str::plural('placement', $inventoryTotal) }} available
            @if($inventoryFrom !== null)
                · from <strong class="catalog-inventory-teaser__price">€{{ number_format($inventoryFrom, 2) }}</strong>
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
                    <input type="hidden" name="filters_open" id="filtersOpenField" value="{{ $filtersExpanded ? '1' : '0' }}">
                    <div class="row g-2 g-md-3 align-items-start">
                        <!-- Primary: Search (site + category/country/language text) -->
                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                            <input type="text"
                                   name="search"
                                   class="form-control form-control-sm"
                                   placeholder="Site, category, country, language…"
                                   value="{{ request('search') }}"
                                   autocomplete="off">
                        </div>

                        <!-- Primary: Category (searchable dropdown) -->
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Category</label>
                            <div class="multi-select-wrapper" data-multi-select="category">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('categoryMultiDropdown', this)">
                                    <div class="selected-items" id="selectedCategoriesDisplay" data-placeholder="All categories">
                                        <span class="placeholder-text">All categories</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="categoryMultiDropdown" role="listbox">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="categorySearch" class="form-control form-control-sm" aria-label="Search categories" placeholder="Type to search categories…" onkeyup="filterMultiOptions('categoryMultiOptions', this.value)" autocomplete="off">
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
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Country</label>
                            <div class="multi-select-wrapper" data-multi-select="country">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('countryMultiDropdown', this)">
                                    <div class="selected-items" id="selectedCountriesDisplay" data-placeholder="All countries">
                                        <span class="placeholder-text">All countries</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="countryMultiDropdown" role="listbox">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="countrySearch" class="form-control form-control-sm" aria-label="Search countries" placeholder="Type to search countries…" onkeyup="filterMultiOptions('countryMultiOptions', this.value)" autocomplete="off">
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
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Language</label>
                            <div class="multi-select-wrapper" data-multi-select="language">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('languageMultiDropdown', this)">
                                    <div class="selected-items" id="selectedLanguagesDisplay" data-placeholder="All languages">
                                        <span class="placeholder-text">All languages</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="languageMultiDropdown" role="listbox">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="languageSearch" class="form-control form-control-sm" aria-label="Search languages" placeholder="Type to search languages…" onkeyup="filterMultiOptions('languageMultiOptions', this.value)" autocomplete="off">
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
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Price (€)</label>
                            <div class="d-flex gap-2">
                                <input type="number"
                                       name="price_min"
                                       id="priceMinInput" aria-label="Minimum price in euros"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Min"
                                       min="0" step="0.01"
                                       value="{{ request('price_min') }}">
                                <input type="number"
                                       name="price_max"
                                       id="priceMaxInput" aria-label="Maximum price in euros"
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
                        <div class="col-12 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-primary px-3" id="applyFiltersBtn">
                                    <i class="fa-solid fa-filter me-1" aria-hidden="true"></i> Filter
                                </button>
                                <button type="button" class="btn btn-sm btn-cta-secondary px-2" id="toggleMoreFiltersBtn" aria-controls="moreFiltersDrawer" aria-expanded="{{ $moreFiltersOpen ? 'true' : 'false' }}">
                                    More
                                    @if($moreFiltersOpen)
                                        <span class="badge rounded-pill ms-1" style="background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#1a585e);border:1px solid var(--brand-primary-border,#b8e4e4);">{{ collect($moreFilterKeys)->filter(fn($k) => filled(request($k)))->count() }}</span>
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
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Sponsored</label>
                                <select name="sponsored" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('sponsored') == '1' ? 'selected' : '' }}>Sponsored Only</option>
                                </select>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Favorites</label>
                                <select name="favorites_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('favorites_filter') == '1' ? 'selected' : '' }}>Favorites Only</option>
                                </select>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Blacklist</label>
                                <select name="blacklist_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('blacklist_filter') == '1' ? 'selected' : '' }}>Blacklisted Only</option>
                                </select>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <abbr class="metric-abbr text-decoration-none" title="Moz Domain Authority — site strength score from 0–100">DA</abbr>
                                </label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="da_min" id="daMinInput" aria-label="Minimum Domain Authority" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('da_min') }}">
                                    <input type="number" name="da_max" id="daMaxInput" aria-label="Maximum Domain Authority" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('da_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="da">
                                    <button type="button" class="filter-preset" data-min="20" data-max="" data-target-min="daMinInput" data-target-max="daMaxInput">DA 20+</button>
                                    <button type="button" class="filter-preset" data-min="40" data-max="" data-target-min="daMinInput" data-target-max="daMaxInput">DA 40+</button>
                                </div>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <abbr class="metric-abbr text-decoration-none" title="Ahrefs Domain Rating — backlink strength score from 0–100">DR</abbr>
                                </label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="dr_min" id="drMinInput" aria-label="Minimum Domain Rating" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('dr_min') }}">
                                    <input type="number" name="dr_max" id="drMaxInput" aria-label="Maximum Domain Rating" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('dr_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="dr">
                                    <button type="button" class="filter-preset" data-min="30" data-max="" data-target-min="drMinInput" data-target-max="drMaxInput">DR 30+</button>
                                    <button type="button" class="filter-preset" data-min="50" data-max="" data-target-min="drMinInput" data-target-max="drMaxInput">DR 50+</button>
                                </div>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Monthly Traffic</label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="traffic_min" id="trafficMinInput" aria-label="Minimum monthly traffic" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" max="4294967295" step="1" inputmode="numeric" value="{{ request('traffic_min') }}">
                                    <input type="number" name="traffic_max" id="trafficMaxInput" aria-label="Maximum monthly traffic" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" max="4294967295" step="1" inputmode="numeric" value="{{ request('traffic_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="traffic">
                                    <button type="button" class="filter-preset" data-min="10000" data-max="" data-target-min="trafficMinInput" data-target-max="trafficMaxInput">10k+</button>
                                    <button type="button" class="filter-preset" data-min="50000" data-max="" data-target-min="trafficMinInput" data-target-max="trafficMaxInput">50k+</button>
                                </div>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
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
                            @php
                                // Drop only this chip's own keys; page resets so the
                                // narrower result set does not land on an empty page.
                                $chipRemoveUrl = route('advertiser.catalog', collect(request()->query())
                                    ->except(array_merge($chip['params'], ['page']))
                                    ->all());
                            @endphp
                            <span class="badge rounded-pill filter-chip">
                                {{ $chip['label'] }}
                                <a href="{{ $chipRemoveUrl }}"
                                   class="filter-chip__remove"
                                   aria-label="Remove filter: {{ $chip['label'] }}"
                                   title="Remove this filter">&times;</a>
                            </span>
                        @endforeach
                        <a href="{{ route('advertiser.catalog') }}" class="small ms-1 catalog-clear-all">Clear all</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

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
                            class="form-select form-select-sm catalog-sort-select">
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
                    <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
                </button>
            </div>

            @if(isset($bulkDeals) && $bulkDeals->count())
            {{-- One row that scrolls sideways, not a grid that wraps.
                 As a grid, twelve deals stacked into three rows of cards and
                 pushed the results table most of a screen down — the section
                 grew with the offer count and the catalog paid for it. A rail
                 is the same height whether there are two deals or twenty. --}}
            <section class="card border-0 shadow-sm mb-3 catalog-bulk-section"
                     data-bulk-rail
                     aria-labelledby="bulkDealsHeading">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="min-w-0">
                        <strong id="bulkDealsHeading">
                            <i class="fa-solid fa-tags me-1 text-success" aria-hidden="true"></i>
                            Bulk discount deals
                            <span class="badge rounded-pill catalog-bulk-count">{{ $bulkDeals->count() }}</span>
                        </strong>
                        <div class="small text-muted">Buy 3–5 articles on these sites and save 10–15%. Totals at checkout include the discount.</div>
                    </div>

                    <div class="catalog-bulk-controls">
                        <button type="button"
                                class="catalog-bulk-nav"
                                data-bulk-scroll="prev"
                                aria-controls="bulkDealsRail"
                                aria-label="Show previous bulk deals">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="catalog-bulk-nav"
                                data-bulk-scroll="next"
                                aria-controls="bulkDealsRail"
                                aria-label="Show more bulk deals">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-link catalog-bulk-toggle"
                                data-bulk-toggle
                                aria-expanded="true"
                                aria-controls="bulkDealsBody">
                            <span data-bulk-toggle-label>Hide</span>
                        </button>
                    </div>
                </div>

                <div class="card-body" id="bulkDealsBody">
                    <div class="catalog-bulk-rail"
                         id="bulkDealsRail"
                         data-bulk-track
                         tabindex="0"
                         role="group"
                         aria-label="Bulk discount deals, scrollable">
                        @foreach($bulkDeals as $deal)
                            @php
                                $unit = (float) $deal->price;
                                $pct = (float) $deal->bulk_discount_percent;
                                $qtyExample = 3;
                                $list = round($unit * $qtyExample, 2);
                                $save = round($list * ($pct / 100), 2);
                                $after = round($list - $save, 2);
                                // Same identity the results table shows, so a listing
                                // whose address is still masked stays masked here.
                                $dealHost = $urlVisibility->hostFor($currentUser, $deal);
                            @endphp
                            <article class="bulk-deal-card">
                                <div class="bulk-deal-card__head">
                                    @include('advertiser.partials.catalog-site-tile', [
                                        'label' => $dealHost,
                                        'size' => 'md',
                                    ])
                                    <span class="bulk-deal-card__host" title="{{ $dealHost }}">{{ $dealHost }}</span>
                                    <span class="bulk-deal-card__pct">−{{ rtrim(rtrim(number_format($pct, 1), '0'), '.') }}%</span>
                                </div>

                                <div class="bulk-deal-card__metrics">
                                    <span>DR <strong>{{ $deal->dr }}</strong></span>
                                    <span>DA <strong>{{ $deal->da }}</strong></span>
                                </div>

                                <div class="bulk-deal-card__price">
                                    <span class="bulk-deal-card__was">€{{ number_format($list, 2) }}</span>
                                    <strong class="bulk-deal-card__now">€{{ number_format($after, 2) }}</strong>
                                    <span class="bulk-deal-card__qty">for {{ $qtyExample }}</span>
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-primary buy-now bulk-deal-card__cta"
                                        data-id="{{ $deal->id }}"
                                        data-base-price="{{ $deal->price }}"
                                        data-publisher-price="{{ $deal->original_price ?? $deal->price }}"
                                        data-name="{{ $deal->site_name }}"
                                        data-bulk-hint="1"
                                        data-bulk-qty="{{ $qtyExample }}"
                                        aria-label="Add {{ $dealHost }} to cart">
                                    Add to cart
                                </button>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            <!-- Publishers Table -->
            {{-- Sorting and paging are full reloads. Without this the click looked
                 dead for as long as the request took. --}}
            <div class="card border-0 shadow-sm catalog-results-card" id="catalogResults" aria-live="polite">
                <div class="catalog-results-busy" hidden aria-hidden="true">
                    <span class="catalog-results-busy__spinner"></span>
                    <span class="catalog-results-busy__label">Updating results…</span>
                </div>
                <div class="card-body p-0">
                    
                    {{-- Desktop table only. Cards own every width below xl so the
                         Buy column is never trapped behind a nested scroller.
                         Vertical scroll is the page; thead stays sticky under
                         the topbar (see catalog.css). --}}
                    <div class="table-responsive catalog-table-scroll d-none d-xl-block">
    <table class="table table-borderless align-middle mb-0 data-table catalog-table">
        <caption class="visually-hidden">Publisher catalog results with metrics, pricing and buy actions</caption>
        <thead class="table-light">
            <tr>
                <th scope="col" class="text-start catalog-th catalog-th-site">
                    <span class="catalog-th-label">
                        Site
                        <x-glass-tip
                            title="Site"
                            body="Part of each domain is hidden so publisher inventory can't be harvested. Open an address to inspect the site — it stays open for you afterwards, and anything in your cart is never masked."
                            label="About Site column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Category
                        <x-glass-tip
                            title="Category"
                            body="Topic niches this site accepts for guest posts and placements."
                            label="About Category column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        @include('advertiser.partials.metric-source', ['type' => 'traffic'])
                        <span class="catalog-th-text">Traffic</span>
                        <x-glass-tip
                            title="Monthly Traffic"
                            body="Estimated monthly visits from analytics data. Higher traffic usually means more reach for your placement."
                            label="About Traffic column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        @include('advertiser.partials.metric-source', ['type' => 'dr'])
                        <span class="catalog-th-text">DR</span>
                        <x-glass-tip
                            title="Domain Rating (DR)"
                            body="Ahrefs Domain Rating (0–100): how strong the site’s backlink profile is compared to others on the web."
                            label="About Domain Rating"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        @include('advertiser.partials.metric-source', ['type' => 'da'])
                        <span class="catalog-th-text">DA</span>
                        <x-glass-tip
                            title="Domain Authority (DA)"
                            body="Moz Domain Authority (0–100): an overall site authority score used to compare ranking potential."
                            label="About Domain Authority"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                    <span class="catalog-th-label">
                        Country
                        <x-glass-tip
                            title="Country"
                            body="Primary country / audience market for this publisher website."
                            label="About Country column"
                            placement="bottom" />
                    </span>
                </th>
                <th scope="col" class="text-center catalog-th catalog-th-action">
                    <span class="catalog-th-label">
                        Buy
                        <x-glass-tip
                            title="Buy"
                            body="See the price, add a placement to your cart, save the site to favorites, or blacklist it so it stays out of your way."
                            label="About Buy column"
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
                $isOwnedByMe = (int) $site->publisher_id === (int) auth()->id();
                // Decode sensitive prices (only positive numeric add-ons are selectable)
                $sensitivePrices = $site->sensitive_prices;
                if (is_string($sensitivePrices)) {
                    $sensitivePrices = json_decode($sensitivePrices, true);
                }
                $sensitivePrices = is_array($sensitivePrices) ? $sensitivePrices : [];
                $sensitivePrices = collect($sensitivePrices)
                    ->filter(fn ($amount, $type) => is_string($type) && $type !== ''
                        && is_numeric($amount) && (float) $amount > 0)
                    ->map(fn ($amount) => round((float) $amount, 2))
                    ->all();

                // List price is the advertiser-facing base (already fee-marked-up).
                // Sale % comes from an active custom discount; JS applies the same
                // (base + sensitive) × (1 − %) math as CartPricingService, floored
                // so the advertiser never pays less than the publisher payout.
                $catalogListPrice = round((float) $site->price, 2);
                // CatalogController sets original_price to the publisher-entered base
                // before applying the portal fee markup onto $site->price.
                $catalogPublisherPrice = round((float) ($site->original_price ?? $site->price), 2);
                $catalogSalePct = $site->activeCustomDiscountPercent();
                $catalogSalePrice = null;
                if ($catalogSalePct) {
                    $rawSale = max(0, round($catalogListPrice - round($catalogListPrice * ($catalogSalePct / 100), 2), 2));
                    $flooredSale = max($catalogPublisherPrice, $rawSale);
                    if ($flooredSale < $catalogListPrice) {
                        $catalogSalePrice = $flooredSale;
                    }
                }
            @endphp
            <tr class="site-row {{ $isBlacklisted ? 'blacklisted-row' : '' }}" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">
                
                <td class="catalog-site-cell">
                    @php
                        // Dynamic "new" flag — listing created within the last 30 days
                        $isNew = $site->created_at->gt(now()->subDays(30));
                    @endphp

                    @php
                        // The real host only reaches the browser once this
                        // advertiser has asked for it and we have logged that.
                        $canSeeUrl = $urlVisibility->canSee($currentUser, $site);
                        $displayHost = $urlVisibility->hostFor($currentUser, $site);
                    @endphp

                    <div class="catalog-site-stack catalog-site-stack--tiled">
                        @include('advertiser.partials.catalog-site-tile', [
                            'label' => $displayHost,
                            'size' => 'md',
                        ])

                        <div class="catalog-site-stack__body">
                        <!-- Host + Verified/NEW + actions stay on one row.
                             Deal chips sit on the next line so a sale/bulk
                             message cannot push status chips down. -->
                        <div class="catalog-site-title-row">
                            <span class="text-dark catalog-site-url"
                                  id="url-host-{{ $site->id }}"
                                  data-site-host
                                  @if($canSeeUrl) data-host="{{ $displayHost }}" @endif
                                  @if(! $canSeeUrl)
                                      data-glass-tip
                                      data-glass-tip-title="Masked for publishers"
                                      data-glass-tip-body="Part of the domain is hidden so publisher inventory can’t be harvested. Every metric you need to judge the site is here — open the address when you want to inspect it."
                                      data-glass-tip-placement="top"
                                  @endif>
                                {{ $displayHost }}
                            </span>

                            {{-- Packed against the domain: eye · NEW · Verified · open · Details. --}}
                            <span class="catalog-site-controls">
                                <span class="catalog-site-actions catalog-site-actions--eye">
                                    <button type="button"
                                            class="btn btn-sm btn-link text-secondary p-0 reveal-url catalog-url-eye {{ $canSeeUrl ? 'd-none' : '' }}"
                                            data-site-id="{{ $site->id }}"
                                            id="url-reveal-{{ $site->id }}"
                                            title="Show the full website address"
                                            aria-label="Show the full website address">
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                    </button>

                                    {{-- Sticky hide: persists until they click the eye again.
                                         The disclosure audit row stays; only display flips. --}}
                                    <button type="button"
                                            class="btn btn-sm btn-link text-secondary p-0 hide-url catalog-url-eye {{ $canSeeUrl ? '' : 'd-none' }}"
                                            data-site-id="{{ $site->id }}"
                                            id="url-hide-{{ $site->id }}"
                                            title="Hide this address"
                                            aria-label="Hide this address">
                                        <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                                    </button>
                                </span>

                                <span class="catalog-site-badges">
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

                                    @if($site->verified)
                                        <button type="button"
                                                class="catalog-verified-mark"
                                                data-glass-tip
                                                data-glass-tip-title="Verified Publisher"
                                                data-glass-tip-body="This publisher has successfully completed our verification process and meets our platform's quality standards."
                                                data-glass-tip-placement="top"
                                                aria-label="Verified publisher">
                                            <img src="{{ asset('assets/img/verified-check.png') }}"
                                                 alt=""
                                                 width="18"
                                                 height="18"
                                                 srcset="{{ asset('assets/img/verified-check.png') }} 1x, {{ asset('assets/img/verified-check@2x.png') }} 2x"
                                                 decoding="async">
                                        </button>
                                    @endif
                                </span>

                                <span class="catalog-site-actions">
                                    {{-- Points at our own redirect, never the domain, so the
                                         row offers a way to inspect the site without printing
                                         its address for anyone reading the page source. --}}
                                    <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-muted site-open-link"
                                       id="url-open-{{ $site->id }}"
                                       title="Open site in a new tab"
                                       aria-label="Open site in a new tab">
                                        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                    </a>

                                    <button type="button"
                                            class="btn btn-sm btn-link text-secondary p-0 expand-arrow catalog-details-toggle"
                                            id="arrow-{{ $site->id }}"
                                            aria-label="Show details for {{ $site->site_name }}"
                                            aria-expanded="false"
                                            aria-controls="site-details-{{ $site->id }}">
                                        <span class="catalog-details-toggle__label">Details</span>
                                        <i class="fa-solid fa-chevron-down ms-1" aria-hidden="true"></i>
                                    </button>
                                </span>
                            </span>
                        </div>

                        @if($site->isFeatured() || $site->hasActiveCustomDiscount() || $site->joinsBulkDiscount())
                        <div class="catalog-site-deals">
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
                        </div>
                        @endif

                        @if($isBlacklisted)
                        <div class="site-status-row">
                            <button type="button"
                                  class="site-chip site-chip--blacklist"
                                  data-glass-tip
                                  data-glass-tip-title="Blacklisted"
                                  data-glass-tip-body="You blacklisted this site — it stays dimmed in your catalog until you remove it."
                                  data-glass-tip-placement="top"
                                  aria-label="Blacklisted site details">
                                <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                <span>Blacklisted</span>
                            </button>
                        </div>
                        @endif

                        @include('advertiser.partials.catalog-meta-chips', [
                            'linkType' => $site->link_type,
                            'turnaround' => $site->turnaround_time,
                        ])
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
                    @include('advertiser.partials.catalog-metric', [
                        'type' => 'traffic',
                        'value' => $site->traffic,
                        'inline' => false,
                    ])
                </td>

                <td class="text-center catalog-stat-cell">
                    @include('advertiser.partials.catalog-metric', [
                        'type' => 'dr',
                        'value' => $site->dr,
                        'inline' => false,
                    ])
                </td>

                <td class="text-center catalog-stat-cell">
                    @include('advertiser.partials.catalog-metric', [
                        'type' => 'da',
                        'value' => $site->da,
                        'inline' => false,
                    ])
                </td>

                <td class="text-center catalog-stat-cell">
                    @php
                        $countryCode = $site->primaryCountryCode() ?: $site->country;
                    @endphp
                    <div class="catalog-country">
                        <span class="catalog-country__flag" aria-hidden="true">{!! getCountryFlag($countryCode) !!}</span>
                        <span class="catalog-country__name text-muted small"
                              title="{{ fullCountry($countryCode) }}">{{ fullCountry($countryCode) }}</span>
                    </div>
                </td>

                <td class="text-center catalog-stat-cell catalog-td-action">
                    <div class="catalog-row-actions">
                        @include('advertiser.partials.catalog-price', [
                            'listPrice' => $catalogListPrice,
                            'salePrice' => $catalogSalePrice,
                            'salePercent' => $catalogSalePct,
                            'align' => 'center',
                        ])

                        <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2"
                                data-id="{{ $site->id }}"
                                data-base-price="{{ $catalogListPrice }}"
                                data-publisher-price="{{ $catalogPublisherPrice }}"
                                data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                                data-name="{{ $site->site_name }}"
                                aria-label="Buy placement for {{ $site->site_name }}">
                            <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                            <span>Add to cart</span>
                        </button>

                        <div class="catalog-row-actions__secondary">
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

                        @unless($isOwnedByMe)
                            <button type="button"
                                    class="btn-claim-site"
                                    data-site-id="{{ $site->id }}"
                                    data-site-name="{{ $site->site_name }}"
                                    data-site-url="{{ $canSeeUrl ? $site->site_url : '' }}"
                                    title="Claim this website if you own it"
                                    aria-label="Claim website {{ $site->site_name }}">
                                Claim
                            </button>
                        @endunless
                        </div>
                    </div>
                </td>
            </tr>

            <tr class="expanded-row-{{ $site->id }}" id="site-details-{{ $site->id }}" style="display: none;">
    <td colspan="7" class="catalog-expand-cell">
        <div class="row">
            <div class="col-md-12">
                <h6 class="mb-3">Site Details</h6>

                {{-- Expandable panel: screenshot + tags/DF links + sample only (no DR/DA/traffic/country) --}}
                <div class="row align-items-start g-4">

                    <div class="col-md-3 text-center">
                        <p class="small text-muted mb-2"><strong>Homepage preview</strong></p>
                        @php
                            // Homepage capture first (full → thumb), then admin/marketing upload.
                            // Matches Site::screenshot_* accessors so expand previews stay filled.
                            $previewUrl = $site->screenshot_url ?: $site->screenshot_thumb_url;
                        @endphp
                        @if($previewUrl)
                            <div class="site-preview-zoom">
                                {{-- Eager: Safari often never loads loading=lazy images that start inside display:none expand rows. --}}
                                <img src="{{ $previewUrl }}"
                                     alt="{{ $site->site_name }} homepage preview"
                                     loading="eager"
                                     decoding="async"
                                     class="site-image-thumbnail"
                                     onerror="this.onerror=null;var z=this.closest('.site-preview-zoom');if(z){z.classList.add('is-broken');var f=z.nextElementSibling;if(f){f.classList.remove('d-none');f.classList.add('d-inline-flex');}}">
                            </div>
                            <div class="site-preview-fallback bg-light border rounded d-none align-items-center justify-content-center" aria-hidden="true">
                                <i class="fa-solid fa-image text-muted" style="font-size: 32px;" aria-hidden="true"></i>
                            </div>
                        @else
                            <div class="site-preview-fallback bg-light border rounded d-inline-flex align-items-center justify-content-center" role="img" aria-label="Homepage preview unavailable">
                                <i class="fa-solid fa-image text-muted" style="font-size: 32px;" aria-hidden="true"></i>
                            </div>
                        @endif
                    </div>

                    <div class="col-md-5">
                        <p class="mb-1"><strong class="small">Description</strong></p>
                        <div class="text-muted small">
                            {!! $site->safeDescriptionHtml() !!}
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
                            $completedOrders = (int) ($site->completed_orders_count ?? 0);
                        @endphp
                        <div class="site-trust-compact mt-2" data-site-id="{{ $site->id }}">
                            <span class="site-trust-compact__stars" aria-label="Average rating {{ $count > 0 ? number_format($avg, 1) : 'new' }} out of 5">
                                @for($i = 1; $i <= 5; $i++)
                                    <i class="fa-{{ $i <= $roundedAvg && $count > 0 ? 'solid' : 'regular' }} fa-star" aria-hidden="true"></i>
                                @endfor
                                <span class="site-trust-compact__score">{{ $count > 0 ? number_format($avg, 1) : 'New' }}</span>
                            </span>
                            <span class="site-trust-compact__sep" aria-hidden="true">·</span>
                            <span class="site-trust-compact__orders" title="Completed orders on this site">
                                @if($completedOrders > 0)
                                    {{ $completedOrders }} completed
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
                                        <i class="fa-solid fa-link me-1" aria-hidden="true"></i>{{ $site->link_type }}
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
                                        <i class="fa-solid fa-star me-1" aria-hidden="true"></i>Sponsored
                                    </span>
                                @endif

                                @if($site->partner_material)
                                    <span class="badge bg-success-subtle text-success border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Partner content allowed">
                                        <i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Partner
                                    </span>
                                @endif

                                @if($site->as_you_prefer ?? false)
                                    <span class="badge bg-primary-subtle text-primary border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Flexible placement">
                                        <i class="fa-solid fa-sliders-h me-1" aria-hidden="true"></i>As You Prefer
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
                                         data-base-price="{{ $catalogListPrice }}"
                                         data-publisher-price="{{ $catalogPublisherPrice }}"
                                         data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                                         role="radiogroup"
                                         aria-label="Sensitive topic pricing">

                                        <div class="form-check mb-2">
                                            <input class="form-check-input sensitive-price-checkbox"
                                                   type="radio"
                                                   name="sensitive_prices_{{ $site->id }}"
                                                   value="0"
                                                   data-type="none"
                                                   data-additional-price="0"
                                                   data-total-price="{{ $catalogSalePrice ?? $catalogListPrice }}"
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
                                                $listWithAddon = round($catalogListPrice + (float) $additionalPrice, 2);
                                                $publisherFloor = round($catalogPublisherPrice + (float) $additionalPrice, 2);
                                                $totalPrice = $listWithAddon;
                                                if ($catalogSalePct) {
                                                    $raw = max(0, round($listWithAddon - round($listWithAddon * ($catalogSalePct / 100), 2), 2));
                                                    $totalPrice = max($publisherFloor, $raw);
                                                }
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
                                            <strong>€{{ number_format($catalogSalePrice ?? $catalogListPrice, 2) }}</strong>
                                            @if($catalogSalePrice !== null)
                                                <span class="text-decoration-line-through">€{{ number_format($catalogListPrice, 2) }}</span>
                                                (offer price)
                                            @else
                                                (Base price)
                                            @endif
                                        </small>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <p><strong>Sample article:</strong></p>

                        {{-- The sample article lives on the same domain, so printing
                             it would hand over the address the row is masking. --}}
                        <div class="d-flex flex-column gap-2">
                            @if(! $canSeeUrl)
                                <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="btn btn-sm btn-outline-secondary" style="width: fit-content;">
                                    <i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>
                                    Open site
                                </a>
                                <span class="text-muted small">
                                    Show the address on this row to see the sample article link.
                                </span>
                            @else
                                @php
                                    // Publisher-supplied, so it cannot go straight into href:
                                    // escaping stops injected markup but not a javascript: scheme.
                                    $sampleUrl = safe_external_url($site->example_url);
                                @endphp
                                <div class="d-flex align-items-center gap-2">
                                    <a href="{{ $sampleUrl }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-decoration-none"
                                       style="word-break: break-all;">
                                        {{ Str::limit($site->example_url ?? 'Not available', 50) }}
                                    </a>

                                    @if($sampleUrl !== '#')
                                        <a href="{{ $sampleUrl }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-muted d-inline-flex align-items-center"
                                           title="Open sample article"
                                           aria-label="Open the sample article for {{ $site->site_name }} in a new tab">
                                            <i class="fa-solid fa-arrow-up-right-from-square"
                                               style="font-size: 13px;" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                </div>

                                @if($site->example_url)
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary copy-example-url"
                                            data-url="{{ $site->example_url }}"
                                            aria-label="Copy the sample article URL for {{ $site->site_name }}"
                                            style="width: fit-content;">
                                        <i class="fa-regular fa-copy" aria-hidden="true"></i> Copy URL
                                    </button>
                                @endif
                            @endif

                            <div class="d-flex align-items-center gap-2">
                                <strong>Publication Duration:</strong>

                                @if($site->publication_time)
                                    <span class="badge text-muted border px-2 py-1"
                                          style="font-size: 11px;"
                                          title="Publication Duration">
                                        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
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
                        @include('advertiser.partials.catalog-empty-art')
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
                                    <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
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

{{-- Card list for everything below xl — same buy/favorite/blacklist actions,
     plus the details the table keeps in its expand row. --}}
<div class="catalog-mobile-list d-xl-none p-3">
    @forelse($sites as $site)
        @php
            $isBlacklisted = in_array($site->id, $blacklist);
            $isFavorited = in_array($site->id, $favorites);
            $isOwnedByMe = (int) $site->publisher_id === (int) auth()->id();
            $isNew = $site->created_at->gt(now()->subDays(30));
            $canSeeUrl = $urlVisibility->canSee($currentUser, $site);
            $displayHost = $urlVisibility->hostFor($currentUser, $site);
            $mobileCategory = is_array($site->categories) && count($site->categories)
                ? $site->categories[0]
                : ($site->category ?? '—');
            if (is_string($mobileCategory) && str_contains($mobileCategory, ',')) {
                $mobileCategory = trim(explode(',', $mobileCategory)[0]);
            }
            $mobileSensitivePrices = $site->sensitive_prices;
            if (is_string($mobileSensitivePrices)) {
                $mobileSensitivePrices = json_decode($mobileSensitivePrices, true);
            }
            $mobileSensitivePrices = is_array($mobileSensitivePrices) ? $mobileSensitivePrices : [];
            $mobileSensitivePrices = collect($mobileSensitivePrices)
                ->filter(fn ($amount, $type) => is_string($type) && $type !== ''
                    && is_numeric($amount) && (float) $amount > 0)
                ->map(fn ($amount) => round((float) $amount, 2))
                ->all();
            $catalogListPrice = round((float) $site->price, 2);
            $catalogPublisherPrice = round((float) ($site->original_price ?? $site->price), 2);
            $catalogSalePct = $site->activeCustomDiscountPercent();
            $catalogSalePrice = null;
            if ($catalogSalePct) {
                $rawSale = max(0, round($catalogListPrice - round($catalogListPrice * ($catalogSalePct / 100), 2), 2));
                $flooredSale = max($catalogPublisherPrice, $rawSale);
                if ($flooredSale < $catalogListPrice) {
                    $catalogSalePrice = $flooredSale;
                }
            }
        @endphp
        <article class="catalog-mobile-card {{ $isBlacklisted ? 'is-blacklisted' : '' }}" data-id="{{ $site->id }}">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="catalog-mobile-card__host d-flex align-items-start gap-2">
                    @include('advertiser.partials.catalog-site-tile', [
                        'label' => $displayHost,
                        'size' => 'lg',
                    ])

                    <div class="catalog-mobile-card__main">
                    <div class="d-flex align-items-center gap-2">
                        {{-- data-host only when the address is currently shown.
                             Hide/show both hit the server so a refresh keeps the
                             chosen state. Never set while the host is masked. --}}
                        <div class="fw-semibold text-dark text-truncate catalog-site-url"
                             id="url-host-mobile-{{ $site->id }}"
                             data-site-host
                             @if($canSeeUrl) data-host="{{ $displayHost }}" @endif>{{ $displayHost }}</div>
                        <a href="{{ route('advertiser.catalog.visit', $site->id) }}"
                           target="_blank" rel="noopener noreferrer"
                           class="text-muted small"
                           title="Open site in a new tab" aria-label="Open site in a new tab">
                            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="catalog-site-badges catalog-site-badges--mobile mt-1">
                        @if($site->verified)
                            <button type="button"
                                    class="catalog-verified-mark"
                                    data-glass-tip
                                    data-glass-tip-title="Verified Publisher"
                                    data-glass-tip-body="This publisher has successfully completed our verification process and meets our platform's quality standards."
                                    data-glass-tip-placement="top"
                                    aria-label="Verified publisher">
                                <img src="{{ asset('assets/img/verified-check.png') }}"
                                     alt=""
                                     width="18"
                                     height="18"
                                     srcset="{{ asset('assets/img/verified-check.png') }} 1x, {{ asset('assets/img/verified-check@2x.png') }} 2x"
                                     decoding="async">
                            </button>
                        @endif
                        @if($isNew)
                            <span class="site-badge-new" aria-label="New listing">NEW</span>
                        @endif
                        @if($site->hasActiveCustomDiscount())
                            <span class="site-chip site-chip--sale" title="Limited-time publisher discount">
                                <i class="fa-solid fa-percent" aria-hidden="true"></i>
                                <span>−{{ rtrim(rtrim(number_format((float) $site->custom_discount_percent, 1), '0'), '.') }}%</span>
                            </span>
                        @endif
                        <span class="category-badge">{{ $mobileCategory }}</span>
                    </div>
                    @include('advertiser.partials.catalog-meta-chips', [
                        'linkType' => $site->link_type,
                        'turnaround' => $site->turnaround_time,
                    ])
                    </div>
                </div>
                {{-- One control, both directions. The card used to carry a
                     reveal button and a toggle button side by side for the same
                     address, and no way to hide it again once revealed. --}}
                <button type="button"
                        class="btn btn-sm btn-link text-secondary p-0 toggle-url btn-icon-quiet"
                        data-id="{{ $site->id }}"
                        data-site-id="{{ $site->id }}"
                        data-url-prefix="mobile"
                        data-target-suffix="mobile"
                        id="url-toggle-mobile-{{ $site->id }}"
                        title="{{ $canSeeUrl ? 'Hide this address' : 'Show the full website address' }}"
                        aria-label="{{ $canSeeUrl ? 'Hide this address' : 'Show the full website address' }}">
                    <i class="fa-regular {{ $canSeeUrl ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
                </button>
            </div>
            @php
                $mobileCountry = $site->primaryCountryCode() ?: $site->country;
                $mobileCountryName = fullCountry($mobileCountry);
            @endphp
            <div class="catalog-mobile-metrics">
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">
                        @include('advertiser.partials.metric-source', ['type' => 'traffic', 'size' => 'sm'])
                        Traffic
                    </span>
                    @include('advertiser.partials.catalog-metric', ['type' => 'traffic', 'value' => $site->traffic, 'inline' => false])
                </div>
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">
                        @include('advertiser.partials.metric-source', ['type' => 'dr', 'size' => 'sm'])
                        DR
                    </span>
                    @include('advertiser.partials.catalog-metric', ['type' => 'dr', 'value' => $site->dr, 'inline' => false])
                </div>
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">
                        @include('advertiser.partials.metric-source', ['type' => 'da', 'size' => 'sm'])
                        DA
                    </span>
                    @include('advertiser.partials.catalog-metric', ['type' => 'da', 'value' => $site->da, 'inline' => false])
                </div>
                <div>
                    <span class="text-muted catalog-mobile-metrics__label">Country</span>
                    <strong title="{{ $mobileCountryName }}">{!! getCountryFlag($mobileCountry) !!} {{ $mobileCountryName }}</strong>
                </div>
            </div>
            @if(!empty($mobileSensitivePrices))
                <div class="sensitive-prices-group mt-3"
                     data-site-id="{{ $site->id }}"
                     data-base-price="{{ $catalogListPrice }}"
                     data-publisher-price="{{ $catalogPublisherPrice }}"
                     data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                     role="radiogroup"
                     aria-label="Sensitive topic pricing">
                    <div class="small fw-semibold mb-1">Additional charges</div>
                    {{-- Its own radio group. Sharing the table's name made the two
                         layouts one group, so the card rendered with nothing
                         selected while the hidden table row held the checked
                         default. JS reads the group that is actually visible. --}}
                    <div class="form-check mb-1">
                        <input class="form-check-input sensitive-price-checkbox"
                               type="radio"
                               name="sensitive_prices_card_{{ $site->id }}"
                               value="0"
                               data-type="none"
                               data-additional-price="0"
                               data-total-price="{{ $catalogSalePrice ?? $catalogListPrice }}"
                               data-site-id="{{ $site->id }}"
                               id="sensitive_mobile_{{ $site->id }}_none"
                               checked>
                        <label class="form-check-label" for="sensitive_mobile_{{ $site->id }}_none">
                            <strong>No sensitive topic</strong>
                            <span class="text-muted">Base price</span>
                        </label>
                    </div>
                    @foreach($mobileSensitivePrices as $type => $additionalPrice)
                        @php
                            $listWithAddon = round($catalogListPrice + (float) $additionalPrice, 2);
                            $publisherFloor = round($catalogPublisherPrice + (float) $additionalPrice, 2);
                            $totalPrice = $listWithAddon;
                            if ($catalogSalePct) {
                                $raw = max(0, round($listWithAddon - round($listWithAddon * ($catalogSalePct / 100), 2), 2));
                                $totalPrice = max($publisherFloor, $raw);
                            }
                        @endphp
                        <div class="form-check mb-1">
                            <input class="form-check-input sensitive-price-checkbox"
                                   type="radio"
                                   name="sensitive_prices_card_{{ $site->id }}"
                                   value="{{ $additionalPrice }}"
                                   data-type="{{ $type }}"
                                   data-additional-price="{{ $additionalPrice }}"
                                   data-total-price="{{ $totalPrice }}"
                                   data-site-id="{{ $site->id }}"
                                   id="sensitive_mobile_{{ $site->id }}_{{ $loop->index }}">
                            <label class="form-check-label" for="sensitive_mobile_{{ $site->id }}_{{ $loop->index }}">
                                <strong>{{ ucfirst($type) }}</strong>
                                <span class="text-danger">€{{ number_format($additionalPrice, 2) }}</span>
                            </label>
                        </div>
                    @endforeach
                    {{-- Rendered server-side like the table's copy. It used to be
                         an empty div until the shopper touched a radio. --}}
                    <div class="selected-price-info mt-1" id="price-info-mobile-{{ $site->id }}">
                        <small class="text-muted">
                            Current price:
                            <strong>€{{ number_format($catalogSalePrice ?? $catalogListPrice, 2) }}</strong>
                            @if($catalogSalePrice !== null)
                                <span class="text-decoration-line-through">€{{ number_format($catalogListPrice, 2) }}</span>
                                (offer price)
                            @else
                                (Base price)
                            @endif
                        </small>
                    </div>
                </div>
            @endif
            <div class="catalog-card-buy">
                @include('advertiser.partials.catalog-price', [
                    'listPrice' => $catalogListPrice,
                    'salePrice' => $catalogSalePrice,
                    'salePercent' => $catalogSalePct,
                    'align' => 'start',
                ])

                <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2"
                        data-id="{{ $site->id }}"
                        data-base-price="{{ $catalogListPrice }}"
                        data-publisher-price="{{ $catalogPublisherPrice }}"
                        data-discount-percent="{{ $catalogSalePct ?? 0 }}"
                        data-name="{{ $site->site_name }}"
                        aria-label="Buy placement for {{ $site->site_name }}">
                    <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                    <span>Add to cart</span>
                </button>
            </div>

            <div class="catalog-row-actions mt-2">
                {{-- Same shape as the table row: Buy owns its line, the quiet
                     controls share the next, so a narrow card does not scatter
                     them across three ragged lines. --}}
                <div class="catalog-row-actions__secondary">
                    <div class="catalog-row-actions-quiet">
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
                    @unless($isOwnedByMe)
                        <button type="button"
                                class="btn-claim-site"
                                data-site-id="{{ $site->id }}"
                                data-site-name="{{ $site->site_name }}"
                                data-site-url="{{ $canSeeUrl ? $site->site_url : '' }}"
                                title="Claim this website if you own it"
                                aria-label="Claim website {{ $site->site_name }}">
                            Claim
                        </button>
                    @endunless
                </div>
            </div>

            {{-- The table keeps this in its expand row, so before the card list
                 covered tablets it was desktop-only: no description, no sample
                 article, no publication window anywhere else. --}}
            <button type="button"
                    class="btn btn-sm btn-link text-secondary p-0 mt-2 catalog-details-toggle catalog-card-details-toggle"
                    data-card-details="card-details-{{ $site->id }}"
                    aria-expanded="false"
                    aria-controls="card-details-{{ $site->id }}">
                <span class="catalog-details-toggle__label">Details</span>
                <i class="fa-solid fa-chevron-down ms-1" aria-hidden="true"></i>
            </button>

            <dl class="catalog-card-details" id="card-details-{{ $site->id }}" hidden>
                <div class="catalog-card-details__row">
                    <dt>Turnaround</dt>
                    <dd>{{ $site->turnaround_time ?? 'Not specified' }}</dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Publication duration</dt>
                    <dd>{{ $site->publication_time ?: 'Not specified' }}</dd>
                </div>
                <div class="catalog-card-details__row">
                    <dt>Link type</dt>
                    <dd>Max 03 {{ $site->link_type ?: 'DoFollow' }} links</dd>
                </div>
                @if($site->description)
                    <div class="catalog-card-details__row">
                        <dt>About this site</dt>
                        <dd>{{ Str::limit($site->description, 260) }}</dd>
                    </div>
                @endif
                <div class="catalog-card-details__row">
                    <dt>Sample article</dt>
                    <dd>
                        {{-- The sample lives on the same domain, so printing it
                             would hand over the address the card is masking. --}}
                        @if(! $canSeeUrl)
                            Show the address on this card to see the sample article link.
                        @elseif($site->example_url)
                            @php $mobileSampleUrl = safe_external_url($site->example_url); @endphp
                            <a href="{{ $mobileSampleUrl }}" target="_blank" rel="noopener noreferrer">
                                {{ Str::limit($site->example_url, 46) }}
                            </a>
                        @else
                            Not available
                        @endif
                    </dd>
                </div>
            </dl>
        </article>
    @empty
        <div class="catalog-empty-state mx-auto text-center py-4">
            @include('advertiser.partials.catalog-empty-art')
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
                        <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
                    </button>
                </div>
            @endif
        </div>
    @endforelse
</div>

                    <!-- Pagination — sized so Prev/Next never swallow the results text -->
                    <nav class="catalog-pagination" aria-label="Catalog pages">
                        @if($resultTotal > 0)
                            <p class="catalog-pagination__meta">
                                Showing
                                <strong>{{ $sites->firstItem() }}–{{ $sites->lastItem() }}</strong>
                                of <strong>{{ number_format($resultTotal) }}</strong>
                                {{ Str::plural('site', $resultTotal) }}
                            </p>
                        @endif
                        <div class="catalog-pagination__links">
                            {{ $sites->links() }}
                        </div>
                    </nav>

                </div>
            </div>
    
        </div>
    </div>

</div>

<script>
window.CatalogConfig = {
    favorites: @json($favorites ?? []),
    blacklist: @json($blacklist ?? []),
    categoryParam: @json((string) request('category', '')),
    countryParam: @json((string) request('country', '')),
    languageParam: @json((string) request('language', '')),
    favoritesFilter: @json(request('favorites_filter') == '1'),
    blacklistFilter: @json(request('blacklist_filter') == '1'),
    csrfToken: @json(csrf_token()),
    contactEmail: @json(auth()->user()->email ?? ''),
    routes: {
        favoritesSave: @json(route('advertiser.favorites.save')),
        blacklistSave: @json(route('advertiser.blacklist.save')),
        websiteSuggestionsStore: @json(route('advertiser.website-suggestions.store')),
        siteClaim: @json(route('advertiser.sites.claim')),
        revealUrl: @json(route('advertiser.catalog.reveal-url', ['site' => '__SITE__'])),
        hideUrl: @json(route('advertiser.catalog.hide-url', ['site' => '__SITE__']))
    }
};
</script>
<script src="{{ asset('assets/js/catalog.js') }}?v={{ @filemtime(public_path('assets/js/catalog.js')) ?: '1' }}" defer></script>

@endsection
