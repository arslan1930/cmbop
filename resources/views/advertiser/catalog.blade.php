@extends('advertiser.layouts.app')

@section('content')
<link href="{{ asset('css/catalog.css') }}?v={{ @filemtime(public_path('css/catalog.css')) ?: '1' }}" rel="stylesheet">


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

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @php
        $inGuestPostWizard = request()->boolean('wizard')
            || ! empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language']);
        $siteReadiness = $siteReadiness ?? [];
    @endphp
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
            'title' => 'Place a guest post · Publishers',
            'subtitle' => 'One job here: pick publishers. You can keep browsing with items already in your cart — finish payment from the cart when ready.',
            'linkAll' => true,
            'contentRoute' => route('advertiser.content-library'),
            'actions' => '<button type="button" class="btn btn-sm btn-outline-primary" onclick="openCart()">Open cart</button>'
                .'<a href="'.e(route('advertiser.wizard.start')).'" class="btn btn-sm btn-outline-secondary">Start guided flow</a>',
        ])
    @endif

    @if(($approvedArticleCount ?? 0) === 0 && empty($orderingSubmission))
        <div class="alert alert-info border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small mb-0">
                Each website needs its own <strong>approved</strong> article. You can still add publishers —
                readiness chips show what’s missing, and the cart checklist walks you through assignment.
            </div>
            <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-sm btn-primary">
                <i class="fa fa-upload me-1"></i> Upload article
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
                <i class="fa fa-shopping-cart me-1"></i> Open cart
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
                    Browse verified publishers. Check article readiness beside Buy before you add sites.
                @endif
            </p>
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
                        @php $readyMeta = $siteReadiness[$site->id] ?? null; @endphp
                        @if($readyMeta)
                            @if($readyMeta['ready'])
                                <span class="catalog-article-ready is-ready" title="You have an approved article ready to assign (any site language)">
                                    {{ $readyMeta['label'] }}
                                </span>
                            @else
                                <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}"
                                   class="catalog-article-ready is-needed"
                                   title="Upload an approved article, then assign it in your cart">
                                    {{ $readyMeta['label'] }}
                                </a>
                            @endif
                        @endif

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
                                <img data-src="{{ $previewUrl }}"
                                     alt="{{ $site->site_name }} homepage preview"
                                     loading="lazy"
                                     class="site-image-thumbnail catalog-deferred-preview"
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
    routes: {
        favoritesSave: @json(route('advertiser.favorites.save')),
        blacklistSave: @json(route('advertiser.blacklist.save')),
        websiteSuggestionsStore: @json(route('advertiser.website-suggestions.store'))
    }
};
</script>
<script src="{{ asset('js/catalog.js') }}?v={{ @filemtime(public_path('js/catalog.js')) ?: '1' }}" defer></script>

@endsection
