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
            'us' => 'United States', 'uk' => 'United Kingdom', 'de' => 'Germany',
            'fr' => 'France', 'it' => 'Italy', 'es' => 'Spain', 'pt' => 'Portugal',
            'nl' => 'Netherlands', 'be' => 'Belgium', 'at' => 'Austria', 'ch' => 'Switzerland',
            'ae' => 'United Arab Emirates', 'sa' => 'Saudi Arabia', 'jp' => 'Japan',
            'cn' => 'China', 'kr' => 'South Korea', 'sg' => 'Singapore', 'br' => 'Brazil',
            'mx' => 'Mexico', 'ar' => 'Argentina', 'ru' => 'Russia', 'pl' => 'Poland',
            'se' => 'Sweden', 'no' => 'Norway', 'dk' => 'Denmark', 'fi' => 'Finland',
            'ie' => 'Ireland', 'nz' => 'New Zealand', 'au' => 'Australia', 'in' => 'India',
            'pk' => 'Pakistan', 'bd' => 'Bangladesh', 'lk' => 'Sri Lanka', 'np' => 'Nepal',
            'tr' => 'Turkey', 'eg' => 'Egypt', 'ma' => 'Morocco', 'za' => 'South Africa',
            'ng' => 'Nigeria', 'ke' => 'Kenya', 'gh' => 'Ghana',
            'id' => 'Indonesia', 'my' => 'Malaysia', 'th' => 'Thailand', 'vn' => 'Vietnam',
            'ph' => 'Philippines', 'ir' => 'Iran', 'iq' => 'Iraq', 'sy' => 'Syria', 'jo' => 'Jordan',
            'lb' => 'Lebanon', 'kw' => 'Kuwait', 'qa' => 'Qatar', 'om' => 'Oman', 'ye' => 'Yemen',
            'hu' => 'Hungary', 'ro' => 'Romania', 'cz' => 'Czech Republic', 'sk' => 'Slovakia', 'si' => 'Slovenia',
            'bg' => 'Bulgaria', 'hr' => 'Croatia', 'lt' => 'Lithuania', 'lv' => 'Latvia', 'ee' => 'Estonia', 'gr' => 'Greece', 'cy' => 'Cyprus', 'is' => 'Iceland', 'al' => 'Albania', 'mk' => 'North Macedonia', 'ba' => 'Bosnia and Herzegovina', 'rs' => 'Serbia', 'me' => 'Montenegro', 'md' => 'Moldova', 'by' => 'Belarus', 'ua' => 'Ukraine', 'ge' => 'Georgia', 'am' => 'Armenia', 'az' => 'Azerbaijan', 'kz' => 'Kazakhstan', 'uz' => 'Uzbekistan', 'af' => 'Afghanistan', 'bd' => 'Bangladesh', 'lk' => 'Sri Lanka', 'np' => 'Nepal', 'mm' => 'Myanmar', 'kh' => 'Cambodia', 'la' => 'Laos', 'mn' => 'Mongolia', 'bt' => 'Bhutan', 've' => 'Venezuela', 'co' => 'Colombia', 'pe' => 'Peru', 'ec' => 'Ecuador', 'cl' => 'Chile', 'uy' => 'Uruguay', 'py' => 'Paraguay', 'bo' => 'Bolivia', 'do' => 'Dominican Republic', 'cr' => 'Costa Rica', 'pa' => 'Panama', 'sv' => 'El Salvador', 'hn' => 'Honduras', 'ni' => 'Nicaragua', 'gt' => 'Guatemala', 'cu' => 'Cuba', 'ht' => 'Haiti', 'jm' => 'Jamaica', 'tt' => 'Trinidad and Tobago', 'bb' => 'Barbados', 'bs' => 'Bahamas', 'ag' => 'Antigua and Barbuda', 'dm' => 'Dominica', 'kn' => 'Saint Kitts and Nevis', 'lc' => 'Saint Lucia', 'vc' => 'Saint Vincent and the Grenadines', 'gd' => 'Grenada', 'aw' => 'Aruba', 'an' => 'Netherlands Antilles', 'cw' => 'Curacao',
            
        ];
        return $countries[strtolower($code)] ?? strtoupper($code);
    }

    function fullLanguage($code){
        $languages = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ru' => 'Russian',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic',
            'hi' => 'Hindi', 'tr' => 'Turkish', 'pl' => 'Polish', 'uk' => 'Ukrainian',
            'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
            'el' => 'Greek', 'cs' => 'Czech', 'hu' => 'Hungarian', 'ro' => 'Romanian',
            'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
            'lt' => 'Lithuanian', 'lv' => 'Latvian', 'et' => 'Estonian', 'he' => 'Hebrew',
            'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay',
            'fa' => 'Persian', 'ur' => 'Urdu', 'bn' => 'Bengali', 'ta' => 'Tamil',
            'te' => 'Telugu', 'mr' => 'Marathi', 'gu' => 'Gujarati', 'kn' => 'Kannada',
            'ml' => 'Malayalam', 'ne' => 'Nepali', 'si' => 'Sinhala', 'my' => 'Burmese',
            'km' => 'Khmer', 'lo' => 'Lao', 'mn' => 'Mongolian', 'az' => 'Azerbaijani',
            'ka' => 'Georgian', 'hy' => 'Armenian', 'sq' => 'Albanian', 'mk' => 'Macedonian',
            'bs' => 'Bosnian', 'sr' => 'Serbian', 'me' => 'Montenegrin', 'is' => 'Icelandic',
            'ga' => 'Irish', 'cy' => 'Welsh', 'gd' => 'Scottish Gaelic', 'mt' => 'Maltese',
            'sw' => 'Swahili', 'am' => 'Amharic', 'yo' => 'Yoruba', 'ig' => 'Igbo',
            'ha' => 'Hausa', 'zu' => 'Zulu', 'so' => 'Somali', 'rw' => 'Kinyarwanda',
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
            <h2 class="mb-1 fw-semibold">All Publishers</h2>
            <p class="text-muted mb-0">
                Browse verified publishers and explore available placement opportunities.
            </p>
        </div>
    </div>

    <!-- FILTERS SECTION -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" action="{{ route('advertiser.catalog') }}" id="filterForm">
                        <div class="row g-3 align-items-end">
                            <!-- Search -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                                <input type="text" 
                                       name="search" 
                                       class="form-control form-control-sm" 
                                       placeholder="URL or category..."
                                       value="{{ request('search') }}">
                            </div>

                            <!-- Country Filter -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Country</label>
                                <select name="country" class="form-select form-select-sm">
                                    <option value="">All Countries</option>
                                    @foreach($availableCountries as $countryCode)
                                        <option value="{{ $countryCode }}" {{ request('country') == $countryCode ? 'selected' : '' }}>
                                            {{ fullCountry($countryCode) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Language Filter -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Language</label>
                                <select name="language" class="form-select form-select-sm">
                                    <option value="">All Languages</option>
                                    @foreach($availableLanguages as $langCode)
                                        <option value="{{ $langCode }}" {{ request('language') == $langCode ? 'selected' : '' }}>
                                            {{ fullLanguage($langCode) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>


                            <!-- Sponsored Filter -->
                             <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Sponsored Only</label>
                                <select name="sponsored" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('sponsored') == '1' ? 'selected' : '' }}>Sponsored Only</option>
                                </select>
                            </div>

                            <!-- Price Range -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Price Range</label>
                                <div class="d-flex gap-2">
                                    <input type="number" 
                                           name="price_min" 
                                           class="form-control form-control-sm" 
                                           placeholder="Min"
                                           value="{{ request('price_min') }}">
                                    <input type="number" 
                                           name="price_max" 
                                           class="form-control form-control-sm" 
                                           placeholder="Max"
                                           value="{{ request('price_max') }}">
                                </div>
                            </div>

                            <!-- Favorites Filter -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Favorites</label>
                                <select name="favorites_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('favorites_filter') == '1' ? 'selected' : '' }}>Favorites Only</option>
                                </select>
                            </div>

                            <!-- Blacklist Filter -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">Blacklist</label>
                                <select name="blacklist_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('blacklist_filter') == '1' ? 'selected' : '' }}>Blacklisted Only</option>
                                </select>
                            </div>

                            <!-- DA Range -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">DA Range</label>
                                <div class="d-flex gap-2">
                                    <input type="number" 
                                           name="da_min" 
                                           class="form-control form-control-sm" 
                                           placeholder="Min"
                                           value="{{ request('da_min') }}">
                                    <input type="number" 
                                           name="da_max" 
                                           class="form-control form-control-sm" 
                                           placeholder="Max"
                                           value="{{ request('da_max') }}">
                                </div>
                            </div>

                            <!-- DR Range -->
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small text-muted mb-1">DR Range</label>
                                <div class="d-flex gap-2">
                                    <input type="number" 
                                           name="dr_min" 
                                           class="form-control form-control-sm" 
                                           placeholder="Min"
                                           value="{{ request('dr_min') }}">
                                    <input type="number" 
                                           name="dr_max" 
                                           class="form-control form-control-sm" 
                                           placeholder="Max"
                                           value="{{ request('dr_max') }}">
                                </div>
                            </div>

                            <!-- Traffic Range -->
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Monthly Traffic</label>
                                <div class="d-flex gap-2">
                                    <input type="number" 
                                           name="traffic_min" 
                                           class="form-control form-control-sm" 
                                           placeholder="Min"
                                           value="{{ request('traffic_min') }}">
                                    <input type="number" 
                                           name="traffic_max" 
                                           class="form-control form-control-sm" 
                                           placeholder="Max"
                                           value="{{ request('traffic_max') }}">
                                </div>
                            </div>

        

                            <!-- Action Buttons -->
                            <div class="col-md-2">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary btn-sm px-4">
                                        <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                                    </button>
                                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-secondary btn-sm px-3">
                                        <i class="fa-solid fa-rotate-right me-1"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

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
                                    <th style="min-width: 250px;">Site</th>
                                    <th>Category</th>
                                    <th>Monthly Traffic</th>
                                    <th>AHREFS DR</th>
                                    <th>MOZ DA</th>
                                    <th>Language</th>
                                    <th style="min-width: 180px;">Action</th>
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
                                            </div>

                                        </div>

                                        </td>

                                    <td>
                                        <span class="badge" style="background-color: #e3f2fd; color: #1976d2; border-radius: 4px; padding: 4px 8px; font-weight: 500;">
                                            {{ $site->category }}
                                        </span>
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
                                            <button class="btn btn-primary btn-sm buy-now d-inline-flex justify-content-center align-items-center gap-2"
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
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <p><strong>Description:</strong></p>
                                                        <div class="text-muted small">
                                                            {!! $site->description !!}
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
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
    <div class="sensitive-prices-group" data-site-id="{{ $site->id }}" data-base-price="{{ $site->price }}">
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
                <label class="form-check-label" for="sensitive_{{ $site->id }}_{{ $loop->index }}">
                    <strong>{{ ucfirst($type) }}</strong>
                    <span class="text-danger">€{{ number_format($additionalPrice, 2) }}</span>
                </label>
            </div>
        @endforeach
    </div>
    <div class="selected-price-info mt-2" id="price-info-{{ $site->id }}">
        <small class="text-muted">Current price: <strong>€{{ number_format($site->price, 2) }}</strong> (Base price)</small>
    </div>
@endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
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
                                                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 13px;"></i>
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
                                                                        {{ $site->publication_time }} days
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
    background-color: #0056b3 !important;
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
</style>

<script>
// Initialize favorites and blacklist from database
let favorites = @json($favorites);
let blacklist = @json($blacklist);

// Store selected sensitive price additional amount for each site
let selectedSensitiveAdditionalPrice = {};

// Toast function using layout's toast or create one
function showToast(message, type = 'success') {
    // Try to use layout's toast if available
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
        // Fallback alert
        alert(message);
    }
}

// Update cart badge and sidebar (using layout's cart functions)
function updateCartBadge() {
    if (typeof window.updateCartBadge === 'function') {
        window.updateCartBadge();
    }
}

// Add to cart with combined price (base price + selected sensitive additional price)
function addToCart(id, name, basePrice, additionalPrice = 0) {
    let finalPrice = parseFloat(basePrice) + parseFloat(additionalPrice);
    
    if (typeof window.addToCart === 'function') {
        // Pass the final price to the cart function
        window.addToCart(id, name, finalPrice);
    } else {
        // Fallback: reload page to update cart
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

    // Store selected sensitive price for each site (store both type and price)
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
                // Uncheck all other checkboxes in the same group
                checkboxes.forEach(cb => {
                    if (cb !== this) {
                        cb.checked = false;
                    }
                });
                
                // Store the selected sensitive price info
                let additionalPrice = parseFloat(this.dataset.additionalPrice);
                let totalPrice = parseFloat(this.dataset.totalPrice);
                let priceType = this.dataset.type;
                
                selectedSensitivePrices[siteId] = {
                    type: priceType,
                    additionalPrice: additionalPrice,
                    totalPrice: totalPrice
                };
                
                // Update buy button price
                updateBuyButtonPrice(siteId, basePrice, additionalPrice);
                
                // Update price info display
                let priceInfoDiv = document.getElementById(`price-info-${siteId}`);
                if (priceInfoDiv) {
                    priceInfoDiv.innerHTML = `
                        <small class="text-muted">Base price: <strong>€${basePrice.toFixed(2)}</strong></small><br>
                        <small class="text-success">Selected: <strong>${priceType}</strong> - Total: <strong>€${totalPrice.toFixed(2)}</strong> (+€${additionalPrice.toFixed(2)})</small>
                    `;
                }
                
                showToast(`${priceType} selected: +€${additionalPrice.toFixed(2)} - Total: €${totalPrice.toFixed(2)}`, 'success');
            } else {
                // If unchecking, revert to base price
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

// Update buy button price display
function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0) {
    let buyButton = document.querySelector(`.buy-now[data-id="${siteId}"]`);
    if (buyButton) {
        let priceSpan = buyButton.querySelector('.fw-semibold');
        let totalPrice = basePrice + additionalPrice;
        if (priceSpan) {
            priceSpan.textContent = `€${totalPrice.toFixed(2)}`;
        }
    }
}

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

    // Add to Cart - with selected sensitive price
document.querySelectorAll('.buy-now').forEach(button => {
    button.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        let id = parseInt(this.dataset.id);
        let basePrice = parseFloat(this.dataset.basePrice);
        let name = this.dataset.name;
        
        // Get the selected sensitive price for this site
        let sensitiveType = selectedSensitivePrices[id] ? selectedSensitivePrices[id].type : null;
        let additionalPrice = selectedSensitivePrices[id] ? selectedSensitivePrices[id].additionalPrice : 0;
        let finalPrice = basePrice + additionalPrice;
        
        // Call the global addToCart function with all parameters
        if (typeof window.addToCart === 'function') {
            window.addToCart(id, name, finalPrice, sensitiveType, additionalPrice, basePrice);
        }
        
        // Visual feedback
        let originalText = this.innerHTML;
        this.innerHTML = '<i class="fa-solid fa-check"></i> Added!';
        setTimeout(() => {
            this.innerHTML = originalText;
        }, 1000);
    });
});

    // Favorite functionality (Database)
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

    // Blacklist functionality (Database)
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

@endsection