@extends('advertiser.layouts.app')

@section('content')

@php
    use Illuminate\Support\Str;
    $sites = $sites ?? collect();
    $favorites = $favorites ?? [];
    $blacklist = $blacklist ?? [];
    $cart = $cart ?? [];

    
    function fullCountry($code){
        $countries = [
            'at' => 'Austria',
    'bh' => 'Bahrain',
    'by' => 'Belarus',
    'be' => 'Belgium',
    'br' => 'Brazil',
    'bg' => 'Bulgaria',
    'cn' => 'China',
    'hr' => 'Croatia',
    'cy' => 'Cyprus',
    'cz' => 'Czech Republic',
    'dk' => 'Denmark',
    'eg' => 'Egypt',
    'fi' => 'Finland',
    'fr' => 'France',
    'de' => 'Germany',
    'gr' => 'Greece',
    'hk' => 'Hong Kong',
    'hu' => 'Hungary',
    'iq' => 'Iraq',
    'ie' => 'Ireland',
    'it' => 'Italy',
    'jp' => 'Japan',
    'jo' => 'Jordan',
    'kw' => 'Kuwait',
    'lv' => 'Latvia',
    'lb' => 'Lebanon',
    'lt' => 'Lithuania',
    'lu' => 'Luxembourg',
    'ma' => 'Morocco',
    'nl' => 'Netherlands',
    'no' => 'Norway',
    'om' => 'Oman',
    'pl' => 'Poland',
    'pt' => 'Portugal',
    'qa' => 'Qatar',
    'ro' => 'Romania',
    'ru' => 'Russia',
    'sa' => 'Saudi Arabia',
    'sg' => 'Singapore',
    'sk' => 'Slovakia',
    'si' => 'Slovenia',
    'kr' => 'South Korea',
    'es' => 'Spain',
    'se' => 'Sweden',
    'ch' => 'Switzerland',
    'ua' => 'Ukraine',
    'uk' => 'United Kingdom',
    'us' => 'United States',
    'ae' => 'United Arab Emirates',
    'ye' => 'Yemen',
    'ar' => 'Argentina',
    'bo' => 'Bolivia',
    'cl' => 'Chile',
    'co' => 'Colombia',
    'cr' => 'Costa Rica',
    'cu' => 'Cuba',
    'do' => 'Dominican Republic',
    'ec' => 'Ecuador',
    'sv' => 'El Salvador',
    'gt' => 'Guatemala',
    'hn' => 'Honduras',
    'mx' => 'Mexico',
    'ni' => 'Nicaragua',
    'pa' => 'Panama',
    'py' => 'Paraguay',
    'pe' => 'Peru',
    'pr' => 'Puerto Rico',
    'uy' => 'Uruguay',
    've' => 'Venezuela',
            
        ];
        return $countries[strtolower($code)] ?? strtoupper($code);
    }

    function fullLanguage($code){
        $languages = [
            'en' => 'English',
    'es' => 'Spanish',
    'fr' => 'French',
    'de' => 'German',
    'it' => 'Italian',
    'pt' => 'Portuguese',
    'nl' => 'Dutch',
    'ru' => 'Russian',
    'zh' => 'Chinese',
    'ja' => 'Japanese',
    'ko' => 'Korean',
    'ar' => 'Arabic',
    'tr' => 'Turkish',
    'pl' => 'Polish',
    'uk' => 'Ukrainian',
    'sv' => 'Swedish',
    'da' => 'Danish',
    'no' => 'Norwegian',
    'fi' => 'Finnish',
    'el' => 'Greek',
    'cs' => 'Czech',
    'hu' => 'Hungarian',
    'ro' => 'Romanian',
    'bg' => 'Bulgarian',
    'hr' => 'Croatian',
    'sk' => 'Slovak',
    'sl' => 'Slovenian',
    'lt' => 'Lithuanian',
    'lv' => 'Latvian',
    'et' => 'Estonian',
    'he' => 'Hebrew',
    'th' => 'Thai',
    'vi' => 'Vietnamese',
    'id' => 'Indonesian',
    'ms' => 'Malay',
    'ca' => 'Catalan',
    'gl' => 'Galician',
    'eu' => 'Basque',
    'cy' => 'Welsh',
    'gd' => 'Scottish Gaelic',
    'ga' => 'Irish',
    'lb' => 'Luxembourgish',
    'rm' => 'Romansh',
    'qu' => 'Quechua',
    'ay' => 'Aymara',
    'gn' => 'Guarani',
    'be' => 'Belarusian',
    'ku' => 'Kurdish',
    'ta' => 'Tamil',
        ];
        return $languages[strtolower($code)] ?? strtoupper($code);
    }
    
    function getCountryFlag($countryCode){
        $code = strtoupper($countryCode);
        if ($code === 'UK') $code = 'GB';
        $flag = mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
        return $flag;
    }
    
    function getLanguageFlag($languageCode){
        $languageToCountry = [
            'en' => 'us', 'es' => 'es', 'fr' => 'fr', 'de' => 'de',
            'it' => 'it', 'pt' => 'pt', 'nl' => 'nl', 'ru' => 'ru',
            'zh' => 'cn', 'ja' => 'jp', 'ko' => 'kr', 'ar' => 'sa',
            'hi' => 'in', 'tr' => 'tr', 'pl' => 'pl', 'uk' => 'ua',
            'sv' => 'se', 'da' => 'dk', 'no' => 'no', 'fi' => 'fi',
            'el' => 'gr', 'cs' => 'cz', 'hu' => 'hu', 'ro' => 'ro',
            'bg' => 'bg', 'hr' => 'hr', 'sk' => 'sk', 'sl' => 'si',
            'lt' => 'lt', 'lv' => 'lv', 'et' => 'ee', 'he' => 'il',
            'th' => 'th', 'vi' => 'vn', 'id' => 'id', 'ms' => 'my',
            'ur' => 'pk', 'bn' => 'bd', 'ta' => 'in', 'ne' => 'np',
        ];
        $countryCode = $languageToCountry[strtolower($languageCode)] ?? 'us';
        $code = strtoupper($countryCode);
        $flag = mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
        return $flag;
    }
@endphp

<div class="container-fluid">

    <!-- HEADER -->
    <div class="row mb-3">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Catalog</h2>
            <p class="text-muted mb-0">Browse verified publishers and explore available placement opportunities.</p>
        </div>
    </div>

    <!-- FILTERS SECTION -->
@php
    $moreFilterKeys = ['language','sponsored','favorites_filter','blacklist_filter','da_min','da_max','dr_min','dr_max','traffic_min','traffic_max','new_badge'];
    $moreFiltersOpen = collect($moreFilterKeys)->contains(fn ($k) => filled(request($k)));
    $activeFilterChips = [];
    if (request('search')) $activeFilterChips[] = ['label' => 'Search: '.request('search'), 'key' => 'search'];
    if (request('category')) $activeFilterChips[] = ['label' => 'Category', 'key' => 'category'];
    if (request('country')) $activeFilterChips[] = ['label' => 'Country', 'key' => 'country'];
    if (request('price_min') || request('price_max')) $activeFilterChips[] = ['label' => 'Price', 'key' => 'price'];
    if (request('language')) $activeFilterChips[] = ['label' => 'Language', 'key' => 'language'];
    if (request('sponsored') == '1') $activeFilterChips[] = ['label' => 'Sponsored', 'key' => 'sponsored'];
    if (request('favorites_filter') == '1') $activeFilterChips[] = ['label' => 'Favorites', 'key' => 'favorites_filter'];
    if (request('blacklist_filter') == '1') $activeFilterChips[] = ['label' => 'Blacklist', 'key' => 'blacklist_filter'];
    if (request('da_min') || request('da_max')) $activeFilterChips[] = ['label' => 'DA', 'key' => 'da'];
    if (request('dr_min') || request('dr_max')) $activeFilterChips[] = ['label' => 'DR', 'key' => 'dr'];
    if (request('traffic_min') || request('traffic_max')) $activeFilterChips[] = ['label' => 'Traffic', 'key' => 'traffic'];
    if (request('new_badge') == '1') $activeFilterChips[] = ['label' => 'New sites', 'key' => 'new_badge'];
@endphp
<div class="row mb-3">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="GET" action="{{ route('advertiser.catalog') }}" id="filterForm">
                    <div class="row g-3 align-items-end">
                        <!-- Primary: Search -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                            <input type="text"
                                   name="search"
                                   class="form-control form-control-sm"
                                   placeholder="Search by site name or URL"
                                   value="{{ request('search') }}">
                        </div>

                        <!-- Primary: Category -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Category</label>
                            <div class="multi-select-wrapper">
                                <div class="multi-select-input form-control form-control-sm" onclick="toggleMultiDropdown('categoryMultiDropdown')">
                                    <div class="selected-items" id="selectedCategoriesDisplay">
                                        <span class="placeholder-text">Select categories...</span>
                                    </div>
                                    <i class="fa fa-chevron-down"></i>
                                </div>
                                <div class="multi-select-dropdown" id="categoryMultiDropdown">
                                    <div class="search-box">
                                        <i class="fa fa-search"></i>
                                        <input type="text" id="categorySearch" class="form-control form-control-sm" placeholder="Search categories..." onkeyup="filterMultiOptions('categoryMultiOptions', this.value)">
                                    </div>
                                    <div class="options-list" id="categoryMultiOptions">
                                        @foreach($siteCategories as $category)
                                            <label class="option-item">
                                                <input type="checkbox" value="{{ $category }}" data-type="category" onchange="updateMultiFilter(this)">
                                                <span>{{ $category }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="category" id="selectedCategory" value="{{ request('category') }}">
                        </div>

                        <!-- Primary: Country -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Country</label>
                            <div class="multi-select-wrapper">
                                <div class="multi-select-input form-control form-control-sm" onclick="toggleMultiDropdown('countryMultiDropdown')">
                                    <div class="selected-items" id="selectedCountriesDisplay">
                                        <span class="placeholder-text">Select countries...</span>
                                    </div>
                                    <i class="fa fa-chevron-down"></i>
                                </div>
                                <div class="multi-select-dropdown" id="countryMultiDropdown">
                                    <div class="search-box">
                                        <i class="fa fa-search"></i>
                                        <input type="text" id="countrySearch" class="form-control form-control-sm" placeholder="Search countries..." onkeyup="filterMultiOptions('countryMultiOptions', this.value)">
                                    </div>
                                    <div class="options-list" id="countryMultiOptions">
                                        @foreach($availableCountries as $code => $name)
                                            <label class="option-item">
                                                <input type="checkbox" value="{{ $code }}" data-type="country" data-name="{{ $name }}" onchange="updateMultiFilter(this)">
                                                <span>{{ $name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="country" id="selectedCountry" value="{{ request('country') }}">
                        </div>

                        <!-- Primary: Price -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Price (€)</label>
                            <div class="d-flex gap-2">
                                <input type="number"
                                       name="price_min"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Min"
                                       min="0" step="0.01"
                                       value="{{ request('price_min') }}">
                                <input type="number"
                                       name="price_max"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Max"
                                       min="0" step="0.01"
                                       value="{{ request('price_max') }}">
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm px-3" id="applyFiltersBtn" style="background-color: #3aaeb2; color: white;">
                                    <i class="fa-solid fa-filter me-1"></i> Filter
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary px-3" id="toggleMoreFiltersBtn" aria-expanded="{{ $moreFiltersOpen ? 'true' : 'false' }}">
                                    <i class="fa fa-sliders me-1"></i> More filters
                                    @if($moreFiltersOpen)
                                        <span class="badge rounded-pill ms-1" style="background:#0b6266;">{{ collect($moreFilterKeys)->filter(fn($k) => filled(request($k)))->count() }}</span>
                                    @endif
                                </button>
                                <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm px-3" style="background-color: #e9ecef; color: #495057;">
                                    <i class="fa-solid fa-rotate-right me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- More filters drawer -->
                    <div id="moreFiltersDrawer" class="mt-3 pt-3 border-top" style="{{ $moreFiltersOpen ? '' : 'display:none;' }}">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Language</label>
                                <div class="multi-select-wrapper">
                                    <div class="multi-select-input form-control form-control-sm" onclick="toggleMultiDropdown('languageMultiDropdown')">
                                        <div class="selected-items" id="selectedLanguagesDisplay">
                                            <span class="placeholder-text">Select languages...</span>
                                        </div>
                                        <i class="fa fa-chevron-down"></i>
                                    </div>
                                    <div class="multi-select-dropdown" id="languageMultiDropdown">
                                        <div class="search-box">
                                            <i class="fa fa-search"></i>
                                            <input type="text" id="languageSearch" class="form-control form-control-sm" placeholder="Search languages..." onkeyup="filterMultiOptions('languageMultiOptions', this.value)">
                                        </div>
                                        <div class="options-list" id="languageMultiOptions">
                                            @foreach($availableLanguages as $code => $name)
                                                <label class="option-item">
                                                    <input type="checkbox" value="{{ $code }}" data-type="language" data-name="{{ $name }}" onchange="updateMultiFilter(this)">
                                                    <span>{{ $name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="language" id="selectedLanguage" value="{{ request('language') }}">
                            </div>

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
                                <label class="form-label fw-semibold small text-muted mb-1">DA Range</label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="da_min" class="form-control form-control-sm no-spinner" placeholder="00" min="0" step="1" value="{{ request('da_min') }}">
                                    <input type="number" name="da_max" class="form-control form-control-sm no-spinner" placeholder="99" min="0" step="1" value="{{ request('da_max') }}">
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">DR Range</label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="dr_min" class="form-control form-control-sm no-spinner" placeholder="00" min="0" step="1" value="{{ request('dr_min') }}">
                                    <input type="number" name="dr_max" class="form-control form-control-sm no-spinner" placeholder="99" min="0" step="1" value="{{ request('dr_max') }}">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Monthly Traffic</label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="traffic_min" class="form-control form-control-sm no-spinner" placeholder="00" min="0" step="1" value="{{ request('traffic_min') }}">
                                    <input type="number" name="traffic_max" class="form-control form-control-sm no-spinner" placeholder="999999" min="0" step="1" value="{{ request('traffic_max') }}">
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
    const btn = document.getElementById('toggleMoreFiltersBtn');
    const drawer = document.getElementById('moreFiltersDrawer');
    if (!btn || !drawer) return;
    btn.addEventListener('click', function () {
        const open = drawer.style.display !== 'none';
        drawer.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
});
</script>



    <!-- CONTENT AREA -->
    <div class="row">
        <div class="col-md-12">

            <!-- Publishers Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    
                    <div class="table-responsive">
    <table class="table table-borderless align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th class="text-center" style="min-width: 250px;">Site</th>
                <th class="text-center">Category</th>
                <th class="text-center">Monthly Traffic</th>
                <th class="text-center">AHREFS DR</th>
                <th class="text-center">MOZ DA</th>
                <th class="text-center">Language</th>
                <th class="text-center" style="min-width: 180px;">Action</th>
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
                
                <td style="min-width: 220px; position: relative;">
                    @if($site->verified)
                        <span class="badge bg-success text-white shadow-sm fw-semibold"
                              style="position: absolute; top: 6px; right: 6px; font-size: 10px; padding: 4px 8px; border-radius: 6px; letter-spacing: 0.3px; z-index: 1;"
                              title="Site has been verified for quality and authenticity">
                            VERIFIED
                        </span>
                    @endif

                    @if($isBlacklisted)
                        <span class="badge bg-danger text-white shadow-sm fw-semibold blacklist-badge"
                              style="position: absolute; top: 6px; left: 6px; font-size: 10px; padding: 4px 8px; border-radius: 6px; letter-spacing: 0.3px; z-index: 1;"
                              title="This site is blacklisted">
                            BLACKLISTED
                        </span>
                    @endif

                    
                    <div class="d-flex flex-column gap-1">
                        <!-- URL Row -->
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-dark"
                                  style="font-family: monospace; font-weight: 600; font-size: 13.5px;"
                                  id="url-masked-{{ $site->id }}">
                                {{ substr(Str::of($site->site_url)->replaceMatches('/^(https?:\/\/)?(www\.)?/', ''), 0, 3) }}******
                            </span>

                            <span class="url-full text-muted d-none"
                                  id="url-full-{{ $site->id }}"
                                  style="font-family: monospace; font-weight: 500; font-size: 13.5px;">
                                {{ Str::of($site->site_url)->replaceMatches('/^(https?:\/\/)?(www\.)?/', '') }}
                            </span>

                            <button class="btn btn-sm btn-link text-secondary p-0 toggle-url"
                                    data-id="{{ $site->id }}"
                                    title="Toggle URL"
                                    style="font-size: 15px;">
                                <i class="fa-regular fa-eye"></i>
                            </button>

                            <a href="{{ $site->site_url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-muted"
                               title="Open Website"
                               style="display:inline-flex; align-items:center; text-decoration:none;">
                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 13px;"></i>
                            </a>

                            <i class="fa-solid fa-chevron-down expand-arrow text-muted" 
                               id="arrow-{{ $site->id }}"
                               style="font-size: 13px; cursor: pointer; transition: transform 0.3s ease;">
                            </i>

                            @if($site->created_at->gt(now()->subDays(30)))
                                <span class="new-badge">
                                    NEW
                                    <span class="pulse-dot"></span>
                                </span>
                            @endif
                        </div>

                        <!-- DoFollow Links -->
                        <div class="text-muted" style="font-size: 12.5px;">
                            Max 03 DoFollow links
                        </div>

                        <!-- Turnaround Time -->
                        <div>
                            <span class="turnaround-badge" style="font-size: 12.5px;">
                            Turnaround: {{ $site->turnaround_time ?? 'N/A' }}
                            </span>
                        </div>
                    </div>
                </td>

                <td>
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

                <td>
                    <div class="d-flex align-items-center gap-2">
                        <img src="{{ asset('assets/img/traffic.svg') }}" alt="Traffic" style="width: 18px; height: 18px;" onerror="this.style.display='none'">
                        <span class="fw-semibold" title="Monthly Traffic Semrush estimate">
                            {{ number_format($site->traffic) }}
                        </span>
                    </div>
                </td>

                <td>
                    <div class="d-flex align-items-center gap-2">
                        <img src="{{ asset('assets/img/ahref.jpeg') }}" alt="AHREFS DR" style="width: 18px; height: 18px; border-radius: 2px;" onerror="this.style.display='none'">
                        <span class="fw-semibold text-info" title="AHREFS Domain Rating">
                            {{ $site->dr }}
                        </span>
                    </div>
                </td>

                <td>
                    <div class="d-flex align-items-center gap-2">
                        <img src="{{ asset('assets/img/moz_da.png') }}" alt="MOZ DA" style="width: 18px; height: 18px;" onerror="this.style.display='none'">
                        <span class="fw-semibold text-primary" title="MOZ Domain Authority">
                            {{ $site->da }}
                        </span>
                    </div>
                </td>

                <td>
                    <div class="d-flex flex-column align-items-center gap-1">
                        <span style="font-size: 24px;">{!! getLanguageFlag($site->language) !!}</span>
                        <span class="text-muted small text-center">{{ fullLanguage($site->language) }}</span>
                    </div>
                </td>

                <td>
                    <div class="d-flex flex-column gap-2 align-items-center">
                        <button class="btn btn-sm buy-now d-inline-flex justify-content-center align-items-center gap-2" 
                                style="background-color: #3aaeb2; color: white;"
                                data-id="{{ $site->id }}"
                                data-base-price="{{ $site->price }}"
                                data-name="{{ $site->site_name }}"
                                style="padding: 6px 12px; font-size: 13px; border-radius: 6px;">
                            <i class="fa-solid fa-cart-plus"></i>
                            <span>Buy</span>
                            <span class="fw-semibold base-price-display">€{{ number_format($site->price, 2) }}</span>
                        </button>

                        <div class="d-flex gap-2 justify-content-center" style="width: fit-content;">
                            <button class="btn btn-sm favorite-btn {{ $isFavorited ? 'btn-danger' : 'btn-outline-danger' }}"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    title="{{ $isFavorited ? 'Remove from Favorites' : 'Add to Favorites' }}"
                                    style="padding: 4px 20px; border-radius: 6px;">
                                <i class="fa-{{ $isFavorited ? 'solid' : 'regular' }} fa-heart"></i>
                            </button>

                            <button class="btn btn-sm blacklist-btn {{ $isBlacklisted ? 'btn-dark' : 'btn-outline-secondary' }}"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    title="{{ $isBlacklisted ? 'Remove from Blacklist' : 'Blacklist Site' }}"
                                    style="padding: 4px 20px; border-radius: 6px;">
                                <i class="fa-solid fa-ban"></i>
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

                <div class="row align-items-start g-4">
                    
                    {{-- Bigger Image --}}
                    <div class="col-md-3 text-center">
                        <p><strong>Site Image:</strong></p>

                        @if($site->site_image)
                            <img src="{{ asset('storage/' . $site->site_image) }}"
                                 alt="{{ $site->site_name }}"
                                 loading="lazy"
                                 class="site-image-thumbnail img-fluid"
                                 style="
                                    width: 280px;
                                    height: 180px;
                                    border-radius: 12px;    
                                    object-fit: cover;
                                    object-position: center;
                                    border: 1px solid #ddd;
                                    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                                 ">
                        @else
                            <div class="bg-light border rounded d-inline-flex align-items-center justify-content-center"
                                 style="width: 180px; height: 180px;">
                                <i class="fa-solid fa-image text-muted" style="font-size: 40px;"></i>
                            </div>
                        @endif
                    </div>

                    {{-- Description --}}
                    <div class="col-md-5">
                        <p><strong>Description:</strong></p>
                        <div class="text-muted small">
                            {!! $site->description !!}
                        </div>
                    </div>

                    {{-- Tags --}}
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
                                         data-base-price="{{ $site->price }}">

                                        @foreach($sensitivePrices as $type => $additionalPrice)
                                            @php
                                                $totalPrice = $site->price + $additionalPrice;
                                            @endphp

                                            <div class="form-check mb-2">
                                                <input class="form-check-input sensitive-price-checkbox"
                                                       type="checkbox"
                                                       name="sensitive_prices_{{ $site->id }}[]"
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

                    {{-- Example URL --}}
                    <div class="col-md-2">
                        <p><strong>Example URL:</strong></p>

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
                                       title="Open Website">
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
                    <div class="alert alert-light border text-center mb-0">
                        No publishers available at the moment.
                    </div>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
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
    padding: 12px 8px;
}

.table tbody td {
    padding: 10px 8px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

thead th {
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
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .btn-sm {
        font-size: 0.75rem;
    }
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

.new-badge {
    position: absolute;
    top: 26px;
    right: 6px;
    background: linear-gradient(135deg, #dc3545, #ff6b6b);
    color: #fff;
    font-size: 10px;
    padding: 4px 8px;
    border-radius: 6px;
    font-weight: 600;
    letter-spacing: 0.4px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    z-index: 1;
}

/* Pulse dot (cleaner, smoother) */
.pulse-dot {
    width: 6px;
    height: 6px;
    background-color: #fff;
    border-radius: 50%;
    position: relative;
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
    background: #e3f2fd;
    color: #1976d2;
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
    color: #1976d2;
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

function toggleMultiDropdown(dropdownId) {
    event.stopPropagation();
    var dropdowns = document.querySelectorAll('.multi-select-dropdown');
    for (var i = 0; i < dropdowns.length; i++) {
        if (dropdowns[i].id !== dropdownId) {
            dropdowns[i].classList.remove('show');
        }
    }
    var dropdown = document.getElementById(dropdownId);
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

function filterMultiOptions(optionsId, searchTerm) {
    var options = document.getElementById(optionsId);
    if (!options) return;
    var optionItems = options.querySelectorAll('.option-item');
    var term = searchTerm.toLowerCase();
    
    for (var i = 0; i < optionItems.length; i++) {
        var option = optionItems[i];
        var text = option.querySelector('span').textContent.toLowerCase();
        if (term === '' || text.indexOf(term) !== -1) {
            option.style.display = 'flex';
        } else {
            option.style.display = 'none';
        }
    }
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

// Apply Filters button - submit the form with all selected values
document.getElementById('applyFiltersBtn').addEventListener('click', function() {
    // Update hidden inputs with selected values
    document.getElementById('selectedCategory').value = selectedMultiFilters.category.join(',');
    document.getElementById('selectedCountry').value = selectedMultiFilters.country.join(',');
    document.getElementById('selectedLanguage').value = selectedMultiFilters.language.join(',');
    
    // Submit form
    document.getElementById('filterForm').submit();
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

// Update UI for favorites and blacklist
function updateButtonStates() {
    document.querySelectorAll('.favorite-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        if (favorites.includes(id)) {
            btn.classList.add('btn-danger');
            btn.classList.remove('btn-outline-danger');
            btn.querySelector('i').classList.remove('fa-regular');
            btn.querySelector('i').classList.add('fa-solid');
            btn.title = 'Remove from Favorites';
        } else {
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-outline-danger');
            btn.querySelector('i').classList.remove('fa-solid');
            btn.querySelector('i').classList.add('fa-regular');
            btn.title = 'Add to Favorites';
        }
    });
    
    document.querySelectorAll('.blacklist-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        if (blacklist.includes(id)) {
            btn.classList.add('btn-dark');
            btn.classList.remove('btn-outline-secondary');
            btn.style.backgroundColor = '#6c757d';
            btn.style.color = 'white';
            btn.title = 'Remove from Blacklist';
        } else {
            btn.classList.remove('btn-dark');
            btn.classList.add('btn-outline-secondary');
            btn.style.backgroundColor = '';
            btn.style.color = '';
            btn.title = 'Blacklist Site';
        }
    });
}

// Update buy button price display
function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0) {
    let buyButton = document.querySelector(`.buy-now[data-id="${siteId}"]`);
    if (buyButton) {
        let priceSpan = buyButton.querySelector('.fw-semibold');
        let totalPrice = parseFloat(basePrice) + parseFloat(additionalPrice);
        if (priceSpan) {
            priceSpan.textContent = `€${totalPrice.toFixed(2)}`;
        }
        buyButton.dataset.currentAdditionalPrice = additionalPrice;
    }
}

// Save favorites to database
function saveFavorites() {
    fetch('{{ route("advertiser.favorites.save") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ favorites: favorites })
    }).catch(err => console.error('Error saving favorites:', err));
}

// Save blacklist to database
function saveBlacklist() {
    fetch('{{ route("advertiser.blacklist.save") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ blacklist: blacklist })
    }).catch(err => console.error('Error saving blacklist:', err));
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
                
                if (this.checked) {
                    checkboxes.forEach(cb => {
                        if (cb !== this) {
                            cb.checked = false;
                        }
                    });
                    
                    let additionalPrice = parseFloat(this.dataset.additionalPrice);
                    let totalPrice = parseFloat(this.dataset.totalPrice);
                    let priceType = this.dataset.type;
                    
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
                } else {
                    if (selectedSensitivePrices[siteId] && selectedSensitivePrices[siteId].additionalPrice === parseFloat(this.dataset.additionalPrice)) {
                        delete selectedSensitivePrices[siteId];
                        updateBuyButtonPrice(siteId, basePrice, 0);
                        
                        let priceInfoDiv = document.getElementById(`price-info-${siteId}`);
                        if (priceInfoDiv) {
                            priceInfoDiv.innerHTML = `
                                <small class="text-muted">Current price: <strong>€${basePrice.toFixed(2)}</strong> (Base price)</small>
                            `;
                        }
                        
                        showToast(`Reverted to base price: €${basePrice.toFixed(2)}`, 'info');
                    }
                }
            });
        });
    });

    // Toggle URL visibility
    document.querySelectorAll('.toggle-url').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = this.dataset.id;
            
            let maskedSpan = document.getElementById('url-masked-' + id);
            let fullSpan = document.getElementById('url-full-' + id);
            
            if (maskedSpan.classList.contains('d-none')) {
                maskedSpan.classList.remove('d-none');
                fullSpan.classList.add('d-none');
                this.querySelector('i').classList.remove('fa-eye-slash');
                this.querySelector('i').classList.add('fa-eye');
            } else {
                maskedSpan.classList.add('d-none');
                fullSpan.classList.remove('d-none');
                this.querySelector('i').classList.remove('fa-eye');
                this.querySelector('i').classList.add('fa-eye-slash');
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
            }
        } else {
            expandedRow.style.display = 'none';
            if (arrowElement) {
                arrowElement.classList.remove('rotate-arrow');
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

    // Favorite functionality
    document.querySelectorAll('.favorite-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = favorites.indexOf(id);
            
            if (index === -1) {
                favorites.push(id);
                this.classList.add('btn-danger');
                this.classList.remove('btn-outline-danger');
                this.querySelector('i').classList.remove('fa-regular');
                this.querySelector('i').classList.add('fa-solid');
                this.title = 'Remove from Favorites';
                showToast(`${name} added to favorites!`, 'success');
            } else {
                favorites.splice(index, 1);
                this.classList.remove('btn-danger');
                this.classList.add('btn-outline-danger');
                this.querySelector('i').classList.remove('fa-solid');
                this.querySelector('i').classList.add('fa-regular');
                this.title = 'Add to Favorites';
                showToast(`${name} removed from favorites!`, 'warning');
            }
            
            saveFavorites();
        });
    });

    // Blacklist functionality
    document.querySelectorAll('.blacklist-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = blacklist.indexOf(id);
            let row = this.closest('.site-row');
            let expandedRow = document.querySelector('.expanded-row-' + id);
            
            if (index === -1) {
                blacklist.push(id);
                this.classList.add('btn-dark');
                this.classList.remove('btn-outline-secondary');
                this.style.backgroundColor = '#6c757d';
                this.style.color = 'white';
                this.title = 'Remove from Blacklist';
                showToast(`${name} has been blacklisted!`, 'warning');
                
                @if(!request('blacklist_filter'))
                if (row) {
                    row.style.transition = 'opacity 0.3s ease';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        row.style.display = 'none';
                    }, 300);
                }
                if (expandedRow) {
                    expandedRow.style.transition = 'opacity 0.3s ease';
                    expandedRow.style.opacity = '0';
                    setTimeout(() => {
                        expandedRow.style.display = 'none';
                    }, 300);
                }
                @endif
            } else {
                blacklist.splice(index, 1);
                this.classList.remove('btn-dark');
                this.classList.add('btn-outline-secondary');
                this.style.backgroundColor = '';
                this.style.color = '';
                this.title = 'Blacklist Site';
                showToast(`${name} removed from blacklist!`, 'success');
                
                if (row) {
                    row.style.display = '';
                    row.style.opacity = '';
                    row.style.transition = '';
                }
                if (expandedRow) {
                    expandedRow.style.display = '';
                    expandedRow.style.opacity = '';
                    expandedRow.style.transition = '';
                }
            }
            
            saveBlacklist();
        });
    });
});

// Hide blacklisted sites on page load
@if(!request('blacklist_filter'))
document.querySelectorAll('.site-row').forEach(row => {
    let id = parseInt(row.dataset.id);
    if (blacklist.includes(id)) {
        row.style.opacity = '0';
        setTimeout(() => {
            row.style.display = 'none';
        }, 100);
        let expandedRow = document.querySelector('.expanded-row-' + id);
        if (expandedRow) {
            expandedRow.style.opacity = '0';
            setTimeout(() => {
                expandedRow.style.display = 'none';
            }, 100);
        }
    }
});
@endif
</script>

<script>
// Force change hover color for Sponsored, Favorites, and Blacklist dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Target the specific select elements
    const selectElements = document.querySelectorAll('select[name="sponsored"], select[name="favorites_filter"], select[name="blacklist_filter"]');
    
    selectElements.forEach(select => {
        // Store original options
        let originalOptions = [];
        
        // Function to apply custom styling to options
        function applyCustomStyling() {
            // Get all options
            const options = select.querySelectorAll('option');
            
            options.forEach(option => {
                // Remove existing event listeners by cloning and replacing
                const newOption = option.cloneNode(true);
                option.parentNode.replaceChild(newOption, option);
                
                // Apply custom hover effect
                newOption.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#3aaeb2';
                    this.style.color = 'white';
                });
                
                newOption.addEventListener('mouseleave', function() {
                    if (!this.selected) {
                        this.style.backgroundColor = '';
                        this.style.color = '';
                    }
                });
                
                // Set selected option style
                if (newOption.selected) {
                    newOption.style.backgroundColor = '#3aaeb2';
                    newOption.style.color = 'white';
                }
            });
        }
        
        // Apply styling when dropdown opens
        select.addEventListener('mousedown', function() {
            setTimeout(applyCustomStyling, 10);
        });
        
        // Apply styling on change
        select.addEventListener('change', function() {
            setTimeout(applyCustomStyling, 10);
        });
        
        // Initial application
        applyCustomStyling();
    });
});
</script>

@endsection