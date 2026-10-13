@php
    $marketplaceHref = $marketplaceHref ?? localized_url('marketplace');
    $locale = function_exists('public_locale') ? (string) public_locale() : (string) app()->getLocale();
    $teaserCodes = (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'catalogTeaserCountries'))
        ? \App\Support\PublicI18n::catalogTeaserCountries($locale)
        : ['de'];
    $previewCountry = strtolower((string) ($teaserCodes[0] ?? 'de'));

    $countryNames = [
        'de' => 'Germany', 'fr' => 'France', 'us' => 'United States', 'it' => 'Italy',
        'es' => 'Spain', 'nl' => 'Netherlands', 'pt' => 'Portugal', 'at' => 'Austria',
        'ch' => 'Switzerland', 'ro' => 'Romania', 'gr' => 'Greece', 'dk' => 'Denmark',
        'se' => 'Sweden', 'no' => 'Norway', 'bg' => 'Bulgaria', 'hu' => 'Hungary',
        'ee' => 'Estonia', 'pl' => 'Poland',
    ];

    $fallbackSets = [
        'fr' => [
            ['name' => 'paris**.fr', 'domain_masked' => 'paris**.fr', 'traffic' => 612000, 'dr' => 88, 'da' => 82, 'price' => 241.50],
            ['name' => 'lyon**.fr', 'domain_masked' => 'lyon**.fr', 'traffic' => 498000, 'dr' => 86, 'da' => 80, 'price' => 176.20],
            ['name' => 'marseille**.fr', 'domain_masked' => 'marseille**.fr', 'traffic' => 441000, 'dr' => 84, 'da' => 79, 'price' => 138.40],
        ],
        'us' => [
            ['name' => 'austin**.com', 'domain_masked' => 'austin**.com', 'traffic' => 704000, 'dr' => 90, 'da' => 84, 'price' => 268.10],
            ['name' => 'chicago**.com', 'domain_masked' => 'chicago**.com', 'traffic' => 559000, 'dr' => 87, 'da' => 81, 'price' => 194.75],
            ['name' => 'denver**.com', 'domain_masked' => 'denver**.com', 'traffic' => 486000, 'dr' => 85, 'da' => 80, 'price' => 152.30],
        ],
        'it' => [
            ['name' => 'roma**.it', 'domain_masked' => 'roma**.it', 'traffic' => 588000, 'dr' => 87, 'da' => 81, 'price' => 229.40],
            ['name' => 'milano**.it', 'domain_masked' => 'milano**.it', 'traffic' => 512000, 'dr' => 85, 'da' => 80, 'price' => 171.80],
            ['name' => 'napoli**.it', 'domain_masked' => 'napoli**.it', 'traffic' => 463000, 'dr' => 83, 'da' => 78, 'price' => 141.10],
        ],
        'es' => [
            ['name' => 'madrid**.es', 'domain_masked' => 'madrid**.es', 'traffic' => 601000, 'dr' => 87, 'da' => 81, 'price' => 221.60],
            ['name' => 'barcelona**.es', 'domain_masked' => 'barcelona**.es', 'traffic' => 534000, 'dr' => 85, 'da' => 80, 'price' => 168.90],
            ['name' => 'valencia**.es', 'domain_masked' => 'valencia**.es', 'traffic' => 412000, 'dr' => 82, 'da' => 77, 'price' => 129.50],
        ],
        'nl' => [
            ['name' => 'amsterdam**.nl', 'domain_masked' => 'amsterdam**.nl', 'traffic' => 544000, 'dr' => 86, 'da' => 80, 'price' => 198.40],
            ['name' => 'rotterdam**.nl', 'domain_masked' => 'rotterdam**.nl', 'traffic' => 401000, 'dr' => 83, 'da' => 78, 'price' => 154.20],
            ['name' => 'utrecht**.nl', 'domain_masked' => 'utrecht**.nl', 'traffic' => 318000, 'dr' => 81, 'da' => 76, 'price' => 121.70],
        ],
        'pt' => [
            ['name' => 'lisboa**.pt', 'domain_masked' => 'lisboa**.pt', 'traffic' => 388000, 'dr' => 82, 'da' => 77, 'price' => 156.80],
            ['name' => 'porto**.pt', 'domain_masked' => 'porto**.pt', 'traffic' => 276000, 'dr' => 79, 'da' => 74, 'price' => 118.40],
            ['name' => 'braga**.pt', 'domain_masked' => 'braga**.pt', 'traffic' => 194000, 'dr' => 76, 'da' => 71, 'price' => 96.20],
        ],
        'at' => [
            ['name' => 'vienna**.at', 'domain_masked' => 'vienna**.at', 'traffic' => 412000, 'dr' => 84, 'da' => 79, 'price' => 188.20],
            ['name' => 'graz**.at', 'domain_masked' => 'graz**.at', 'traffic' => 276000, 'dr' => 80, 'da' => 75, 'price' => 132.40],
            ['name' => 'linz**.at', 'domain_masked' => 'linz**.at', 'traffic' => 198000, 'dr' => 77, 'da' => 72, 'price' => 104.60],
        ],
        'ch' => [
            ['name' => 'zurich**.ch', 'domain_masked' => 'zurich**.ch', 'traffic' => 468000, 'dr' => 86, 'da' => 81, 'price' => 214.80],
            ['name' => 'geneva**.ch', 'domain_masked' => 'geneva**.ch', 'traffic' => 312000, 'dr' => 82, 'da' => 77, 'price' => 156.10],
            ['name' => 'bern**.ch', 'domain_masked' => 'bern**.ch', 'traffic' => 241000, 'dr' => 79, 'da' => 74, 'price' => 121.90],
        ],
    ];

    $heroMetric = static function (string $type, $value): array {
        $raw = (float) ($value ?? 0);
        if ($type === 'traffic') {
            $fill = $raw > 0 ? min(100, (log10($raw + 1) / log10(2000000)) * 100) : 0;
            $display = $raw >= 1000000
                ? rtrim(rtrim(number_format($raw / 1000000, 1), '0'), '.').'M'
                : ($raw >= 1000
                    ? rtrim(rtrim(number_format($raw / 1000, 1), '0'), '.').'k'
                    : number_format($raw));
        } else {
            $fill = max(0, min(100, $raw));
            $display = (string) (int) $raw;
        }

        return ['fill' => round($fill, 1), 'display' => $display];
    };

    $fallbackRows = [
        [
            'name' => 'berlin**.de',
            'domain_masked' => 'berlin**.de',
            'traffic' => 627000,
            'dr' => 89,
            'da' => 83,
            'price' => 253.87,
            'categories' => ['Technology', 'Business'],
            'more_cats' => 1,
            'tag' => 'sponsored',
            'tag_label' => 'Sponsored',
            'tag_icon' => 'fa-star',
            'link_type' => 'DoFollow',
            'turnaround' => '24 hours',
            'verified' => true,
        ],
        [
            'name' => 'munich**.de',
            'domain_masked' => 'munich**.de',
            'traffic' => 580400,
            'dr' => 89,
            'da' => 82,
            'price' => 188.98,
            'categories' => ['Technology', 'Business'],
            'more_cats' => 1,
            'tag' => 'partner',
            'tag_label' => 'Partner article',
            'tag_icon' => 'fa-handshake',
            'link_type' => 'DoFollow',
            'turnaround' => '7 days',
            'verified' => true,
        ],
        [
            'name' => 'hamburg**.de',
            'domain_masked' => 'hamburg**.de',
            'traffic' => 501200,
            'dr' => 88,
            'da' => 83,
            'price' => 149.63,
            'categories' => ['Technology', 'Business'],
            'more_cats' => 1,
            'tag' => 'sponsored',
            'tag_label' => 'Sponsored',
            'tag_icon' => 'fa-star',
            'link_type' => 'DoFollow',
            'turnaround' => '3 days',
            'verified' => true,
        ],
    ];

    $defaults = [
        'categories' => ['Technology', 'Business'],
        'more_cats' => 1,
        'tag' => 'sponsored',
        'tag_label' => __('messages.catalog_preview_sponsored'),
        'tag_icon' => 'fa-star',
        'link_type' => 'DoFollow',
        'turnaround' => '7 days',
        'verified' => true,
        'country' => $previewCountry,
        'country_name' => $countryNames[$previewCountry] ?? strtoupper($previewCountry),
    ];

    $localizedFallbacks = $fallbackSets[$previewCountry] ?? null;
    if (is_array($localizedFallbacks)) {
        $fallbackRows = [];
        foreach ($localizedFallbacks as $index => $row) {
            $fallbackRows[] = array_merge($defaults, $row, [
                'tag' => $index === 1 ? 'partner' : 'sponsored',
                'tag_label' => $index === 1 ? __('messages.catalog_preview_partner') : __('messages.catalog_preview_sponsored'),
                'tag_icon' => $index === 1 ? 'fa-handshake' : 'fa-star',
            ]);
        }
    } else {
        $fallbackRows = array_map(static function (array $row) use ($defaults, $countryNames) {
            return array_merge($defaults, $row, [
                'country' => 'de',
                'country_name' => $countryNames['de'],
                'tag_label' => $row['tag'] === 'partner'
                    ? __('messages.catalog_preview_partner')
                    : __('messages.catalog_preview_sponsored'),
            ]);
        }, $fallbackRows);
    }

    $live = collect($catalogPreview ?? []);
    if ($live->isNotEmpty()) {
        $rows = $live->take(3)->map(function ($site) use ($defaults, $countryNames, $previewCountry) {
            $site = is_array($site) ? $site : [];
            $code = strtolower((string) ($site['country'] ?? $previewCountry));

            return array_merge($defaults, $site, [
                'name' => $site['domain_masked'] ?? ($site['name'] ?? ''),
                'country' => $code,
                'country_name' => $countryNames[$code] ?? strtoupper($code),
                'tag_label' => $defaults['tag_label'],
            ]);
        })->all();
    } else {
        $rows = $fallbackRows;
    }
    $rowCount = count($rows);
@endphp

@push('page_styles')
  <link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
  <link href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: '1' }}" rel="stylesheet">
@endpush

<div class="slb-hero-visual">
  <div class="catalog-page slb-hero-catalog-clone" aria-label="{{ __('messages.catalog_preview_aria') }}">
    <div class="card border-0 shadow-sm catalog-filters-card slb-hero-catalog-clone__filters">
      <div class="card-body py-3">
        <div class="row g-2 g-md-3 align-items-start">
          <div class="col-12 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">{{ __('messages.catalog_preview_search') }}</label>
            <div class="catalog-search-field slb-search-wrap">
              <input type="search" class="form-control form-control-sm" placeholder="{{ __('messages.catalog_preview_search_placeholder') }}" disabled tabindex="-1" aria-hidden="true">
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">{{ __('messages.catalog_preview_category') }}</label>
            <div class="multi-select-wrapper">
              <div class="multi-select-input form-control form-control-sm">
                <div class="selected-items"><span class="placeholder-text">{{ __('messages.catalog_preview_all_categories') }}</span></div>
                <i class="fa fa-chevron-down" aria-hidden="true"></i>
              </div>
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">{{ __('messages.catalog_preview_country') }}</label>
            <div class="multi-select-wrapper">
              <div class="multi-select-input form-control form-control-sm">
                <div class="selected-items"><span class="placeholder-text">{{ __('messages.catalog_preview_all_countries') }}</span></div>
                <i class="fa fa-chevron-down" aria-hidden="true"></i>
              </div>
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">{{ __('messages.catalog_preview_language') }}</label>
            <div class="multi-select-wrapper">
              <div class="multi-select-input form-control form-control-sm">
                <div class="selected-items"><span class="placeholder-text">{{ __('messages.catalog_preview_all_languages') }}</span></div>
                <i class="fa fa-chevron-down" aria-hidden="true"></i>
              </div>
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">{{ __('messages.catalog_preview_price') }}</label>
            <div class="d-flex gap-2">
              <input type="text" class="form-control form-control-sm" placeholder="{{ __('messages.catalog_preview_min') }}" disabled tabindex="-1" aria-hidden="true">
              <input type="text" class="form-control form-control-sm" placeholder="{{ __('messages.catalog_preview_max') }}" disabled tabindex="-1" aria-hidden="true">
            </div>
            <div class="filter-presets">
              <span class="filter-preset">{{ __('messages.catalog_preview_under_50') }}</span>
              <span class="filter-preset">{{ __('messages.catalog_preview_50_150') }}</span>
              <span class="filter-preset">{{ __('messages.catalog_preview_150_plus') }}</span>
            </div>
          </div>
          <div class="col-12 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
            <div class="d-flex flex-wrap gap-2">
              <span class="btn btn-sm btn-primary px-3"><i class="fa-solid fa-filter me-1" aria-hidden="true"></i> {{ __('messages.catalog_preview_filter') }}</span>
              <span class="btn btn-sm btn-cta-secondary px-2">{{ __('messages.catalog_preview_more') }}</span>
              <span class="btn btn-sm btn-cta-tertiary px-1">{{ __('messages.catalog_preview_reset') }}</span>
            </div>
          </div>
        </div>

        <div class="catalog-tag-quick mt-2" role="group" aria-label="{{ __('messages.catalog_preview_tag') }}">
          <span class="small text-muted me-1">{{ __('messages.catalog_preview_tag') }}</span>
          <span class="catalog-tag-quick__btn is-active">{{ __('messages.catalog_preview_all_tags') }}</span>
          <span class="catalog-tag-quick__btn">{{ __('messages.catalog_preview_sponsored') }}</span>
          <span class="catalog-tag-quick__btn">{{ __('messages.catalog_preview_partner') }}</span>
          <span class="catalog-tag-quick__btn">{{ __('messages.catalog_preview_as_you_prefer') }}</span>
          <span class="catalog-tag-quick__btn">{{ __('messages.catalog_preview_no_tags') }}</span>
        </div>
      </div>
    </div>

    <div class="catalog-results-bar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 mt-3">
      <div class="text-muted small">{{ __('messages.catalog_preview_showing', ['from' => 1, 'to' => $rowCount, 'total' => $rowCount]) }}</div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="small text-muted mb-0">{{ __('messages.catalog_preview_per_page') }}</span>
        <select class="form-select form-select-sm catalog-sort-select" disabled tabindex="-1" aria-hidden="true">
          <option selected>20</option>
        </select>
        <span class="small text-muted mb-0">{{ __('messages.catalog_preview_sort') }}</span>
        <select class="form-select form-select-sm catalog-sort-select" disabled tabindex="-1" aria-hidden="true">
          <option selected>{{ __('messages.catalog_preview_sort_dr') }}</option>
        </select>
      </div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <p class="small text-muted mb-0">{{ __('messages.catalog_preview_missing') }}</p>
      <span class="btn btn-sm btn-outline-success btn-suggest-website">
        <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> {{ __('messages.catalog_preview_suggest') }}
      </span>
    </div>

    <div class="card border-0 shadow-sm catalog-results-card">
      <div class="card-body p-0">
        <div class="table-responsive catalog-table-scroll">
          <table class="table table-borderless align-middle mb-0 data-table catalog-table">
            <caption class="visually-hidden">{{ __('messages.catalog_preview_aria') }}</caption>
            <thead class="table-light">
              <tr>
                <th scope="col" class="text-start catalog-th catalog-th-site"><span class="catalog-th-label">{{ __('messages.catalog_preview_site') }}</span></th>
                <th scope="col" class="text-center catalog-th"><span class="catalog-th-label">{{ __('messages.catalog_preview_category') }}</span></th>
                <th scope="col" class="text-center catalog-th">
                  <span class="catalog-th-label">
                    @if (view()->exists('advertiser.partials.metric-source'))
                      @include('advertiser.partials.metric-source', ['type' => 'traffic'])
                    @endif
                    <span class="catalog-th-text">{{ __('messages.catalog_preview_traffic') }}</span>
                  </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                  <span class="catalog-th-label">
                    @if (view()->exists('advertiser.partials.metric-source'))
                      @include('advertiser.partials.metric-source', ['type' => 'dr'])
                    @endif
                    <span class="catalog-th-text">DR</span>
                  </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                  <span class="catalog-th-label">
                    @if (view()->exists('advertiser.partials.metric-source'))
                      @include('advertiser.partials.metric-source', ['type' => 'da'])
                    @endif
                    <span class="catalog-th-text">DA</span>
                  </span>
                </th>
                <th scope="col" class="text-center catalog-th"><span class="catalog-th-label">{{ __('messages.catalog_preview_country') }}</span></th>
                <th scope="col" class="text-center catalog-th catalog-th-action"><span class="catalog-th-label">{{ __('messages.catalog_preview_buy') }}</span></th>
              </tr>
            </thead>
            <tbody>
              @foreach($rows as $site)
                @php
                  $traffic = $heroMetric('traffic', $site['traffic']);
                  $dr = $heroMetric('dr', $site['dr']);
                  $da = $heroMetric('da', $site['da']);
                @endphp
                <tr>
                  <td class="catalog-site-cell">
                    <div class="catalog-site-stack catalog-site-stack--tiled">
                      <span class="catalog-tile catalog-tile--md slb-hero-catalog-clone__flag-tile" aria-hidden="true">{!! getCountryFlag($site['country'] ?? $previewCountry) !!}</span>
                      <div class="catalog-site-stack__body">
                        <div class="catalog-site-title-row">
                          <span class="text-dark catalog-site-name">{{ $site['name'] }}</span>
                          <span class="catalog-site-controls">
                            <span class="catalog-site-badges">
                              @if(!empty($site['verified']))
                                <span class="site-chip site-chip--verified site-chip--status">
                                  <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                  <span>{{ __('messages.catalog_preview_verified') }}</span>
                                </span>
                              @endif
                            </span>
                            <span class="catalog-site-actions">
                              <span class="btn btn-sm btn-link text-secondary p-0 catalog-details-toggle">
                                <span class="catalog-details-toggle__label">{{ __('messages.catalog_preview_details') }}</span>
                                <i class="fa-solid fa-chevron-down ms-1" aria-hidden="true"></i>
                              </span>
                            </span>
                          </span>
                        </div>
                        <div class="catalog-site-identity">
                          <span class="catalog-site-rooted-url catalog-site-url">{{ $site['domain_masked'] }}</span>
                          <span class="catalog-site-status-row">
                            <span class="site-chip site-chip--{{ $site['tag'] }} site-chip--descriptor">
                              <i class="fa-solid {{ $site['tag_icon'] }}" aria-hidden="true"></i>
                              <span>{{ $site['tag_label'] }}</span>
                            </span>
                          </span>
                        </div>
                        <div class="catalog-meta-chips">
                          <span class="catalog-meta-chip">
                            <i class="fa-solid fa-link" aria-hidden="true"></i>
                            <span>{{ $site['link_type'] }}</span>
                          </span>
                          <span class="catalog-meta-chip">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span>{{ $site['turnaround'] }}</span>
                          </span>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="text-center catalog-stat-cell catalog-category-cell">
                    <div class="categories-wrapper">
                      <div class="categories-column">
                        @foreach($site['categories'] as $cat)
                          <span class="category-badge">{{ $cat }}</span>
                        @endforeach
                      </div>
                      @if(($site['more_cats'] ?? 0) > 0)
                        <span class="toggle-cats-btn">{{ __('messages.catalog_preview_more_cats', ['count' => $site['more_cats']]) }}</span>
                      @endif
                    </div>
                  </td>
                  <td class="text-center catalog-stat-cell">
                    <div class="catalog-metric catalog-metric--traffic">
                      <span class="catalog-metric__value">{{ $traffic['display'] }}</span>
                      <span class="catalog-metric__bar" aria-hidden="true"><span class="catalog-metric__fill" style="width: {{ $traffic['fill'] }}%"></span></span>
                    </div>
                  </td>
                  <td class="text-center catalog-stat-cell">
                    <div class="catalog-metric catalog-metric--dr {{ $dr['fill'] >= 70 ? 'is-standout' : '' }}">
                      <span class="catalog-metric__value">{{ $dr['display'] }}</span>
                      <span class="catalog-metric__bar" aria-hidden="true"><span class="catalog-metric__fill" style="width: {{ $dr['fill'] }}%"></span></span>
                    </div>
                  </td>
                  <td class="text-center catalog-stat-cell">
                    <div class="catalog-metric catalog-metric--da">
                      <span class="catalog-metric__value">{{ $da['display'] }}</span>
                      <span class="catalog-metric__bar" aria-hidden="true"><span class="catalog-metric__fill" style="width: {{ $da['fill'] }}%"></span></span>
                    </div>
                  </td>
                  <td class="text-center catalog-stat-cell">
                    <div class="catalog-country">
                      <span class="catalog-country__flag" aria-hidden="true">{!! getCountryFlag($site['country'] ?? $previewCountry) !!}</span>
                      <span class="catalog-country__name text-muted small">{{ $site['country_name'] ?? ($countryNames[$previewCountry] ?? 'Germany') }}</span>
                    </div>
                  </td>
                  <td class="text-center catalog-stat-cell catalog-td-action">
                    <div class="catalog-row-actions">
                      <div class="catalog-price catalog-price--center">
                        <div class="catalog-price__row">
                          <span class="catalog-price__pay">{{ format_money($site['price']) }}</span>
                        </div>
                      </div>
                      <span class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2">
                        <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                        <span>{{ __('messages.catalog_preview_add_to_cart') }}</span>
                      </span>
                      <div class="catalog-row-actions__secondary">
                        <div class="catalog-row-actions-quiet">
                          <span class="btn-icon-quiet"><i class="fa-regular fa-heart" aria-hidden="true"></i></span>
                        </div>
                        <span class="btn-claim-site">{{ __('messages.catalog_preview_your_site') }}</span>
                      </div>
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <a href="{{ $marketplaceHref }}" class="slb-hero-catalog-hit" aria-label="{{ __('messages.nav_marketplace') }}"></a>
</div>
