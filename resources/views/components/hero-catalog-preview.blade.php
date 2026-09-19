@php
    $marketplaceHref = $marketplaceHref ?? localized_url('marketplace');

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
            'da' => 74,
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
            'da' => 28,
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
            'da' => 28,
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

    $catalogPreview = $catalogPreview ?? collect();
    if ($catalogPreview instanceof \Illuminate\Support\Collection) {
        $catalogPreview = $catalogPreview
            ->filter(fn ($site) => strtolower((string) ($site['country'] ?? '')) === 'de')
            ->values()
            ->take(3);
    } else {
        $catalogPreview = collect();
    }

    $rows = [];
    foreach ($fallbackRows as $i => $base) {
        $site = $catalogPreview->get($i);
        $rows[] = is_array($site)
            ? array_merge($base, [
                'dr' => $site['dr'] ?? $base['dr'],
                'da' => $site['da'] ?? $base['da'],
                'price' => $site['price'] ?? $base['price'],
                'traffic' => $site['traffic'] ?? $base['traffic'],
            ])
            : $base;
    }

    $rowCount = count($rows);
@endphp

<div class="slb-hero-visual">
  <link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
  <link href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: '1' }}" rel="stylesheet">
  <div class="catalog-page slb-hero-catalog-clone" aria-label="Publisher catalog preview">
    <div class="card border-0 shadow-sm catalog-filters-card slb-hero-catalog-clone__filters">
      <div class="card-body py-3">
        <div class="row g-2 g-md-3 align-items-start">
          <div class="col-12 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">Search</label>
            <div class="catalog-search-field slb-search-wrap">
              <input type="search" class="form-control form-control-sm" placeholder="Name, domain, category…" disabled tabindex="-1" aria-hidden="true">
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">Category</label>
            <div class="multi-select-wrapper">
              <div class="multi-select-input form-control form-control-sm">
                <div class="selected-items"><span class="placeholder-text">All categories</span></div>
                <i class="fa fa-chevron-down" aria-hidden="true"></i>
              </div>
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">Country</label>
            <div class="multi-select-wrapper">
              <div class="multi-select-input form-control form-control-sm">
                <div class="selected-items"><span class="placeholder-text">All countries</span></div>
                <i class="fa fa-chevron-down" aria-hidden="true"></i>
              </div>
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">Language</label>
            <div class="multi-select-wrapper">
              <div class="multi-select-input form-control form-control-sm">
                <div class="selected-items"><span class="placeholder-text">All languages</span></div>
                <i class="fa fa-chevron-down" aria-hidden="true"></i>
              </div>
            </div>
          </div>
          <div class="col-6 col-sm-6 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1">Price (€)</label>
            <div class="d-flex gap-2">
              <input type="text" class="form-control form-control-sm" placeholder="Min" disabled tabindex="-1" aria-hidden="true">
              <input type="text" class="form-control form-control-sm" placeholder="Max" disabled tabindex="-1" aria-hidden="true">
            </div>
            <div class="filter-presets">
              <span class="filter-preset">Under €50</span>
              <span class="filter-preset">€50–150</span>
              <span class="filter-preset">€150+</span>
            </div>
          </div>
          <div class="col-12 col-lg-2">
            <label class="form-label fw-semibold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
            <div class="d-flex flex-wrap gap-2">
              <span class="btn btn-sm btn-primary px-3"><i class="fa-solid fa-filter me-1" aria-hidden="true"></i> Filter</span>
              <span class="btn btn-sm btn-cta-secondary px-2">More</span>
              <span class="btn btn-sm btn-cta-tertiary px-1">Reset</span>
            </div>
          </div>
        </div>

        <div class="catalog-tag-quick mt-2" role="group" aria-label="Listing tag">
          <span class="small text-muted me-1">Tag</span>
          <span class="catalog-tag-quick__btn is-active">All tags</span>
          <span class="catalog-tag-quick__btn">Sponsored</span>
          <span class="catalog-tag-quick__btn">Partner article</span>
          <span class="catalog-tag-quick__btn">As you prefer</span>
          <span class="catalog-tag-quick__btn">No tags</span>
        </div>
      </div>
    </div>

    <div class="catalog-results-bar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 mt-3">
      <div class="text-muted small">Showing 1–{{ $rowCount }} of {{ $rowCount }} sites</div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="small text-muted mb-0">Per page</span>
        <select class="form-select form-select-sm catalog-sort-select" disabled tabindex="-1" aria-hidden="true">
          <option selected>20</option>
        </select>
        <span class="small text-muted mb-0">Sort</span>
        <select class="form-select form-select-sm catalog-sort-select" disabled tabindex="-1" aria-hidden="true">
          <option selected>DR (high → low)</option>
        </select>
      </div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <p class="small text-muted mb-0">Searching for a site that isn’t listed yet?</p>
      <span class="btn btn-sm btn-outline-success btn-suggest-website">
        <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
      </span>
    </div>

    <div class="card border-0 shadow-sm catalog-results-card">
      <div class="card-body p-0">
        <div class="table-responsive catalog-table-scroll">
          <table class="table table-borderless align-middle mb-0 data-table catalog-table">
            <caption class="visually-hidden">Publisher catalog preview</caption>
            <thead class="table-light">
              <tr>
                <th scope="col" class="text-start catalog-th catalog-th-site"><span class="catalog-th-label">Site</span></th>
                <th scope="col" class="text-center catalog-th"><span class="catalog-th-label">Category</span></th>
                <th scope="col" class="text-center catalog-th">
                  <span class="catalog-th-label">
                    @include('advertiser.partials.metric-source', ['type' => 'traffic'])
                    <span class="catalog-th-text">Traffic</span>
                  </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                  <span class="catalog-th-label">
                    @include('advertiser.partials.metric-source', ['type' => 'dr'])
                    <span class="catalog-th-text">DR</span>
                  </span>
                </th>
                <th scope="col" class="text-center catalog-th">
                  <span class="catalog-th-label">
                    @include('advertiser.partials.metric-source', ['type' => 'da'])
                    <span class="catalog-th-text">DA</span>
                  </span>
                </th>
                <th scope="col" class="text-center catalog-th"><span class="catalog-th-label">Country</span></th>
                <th scope="col" class="text-center catalog-th catalog-th-action"><span class="catalog-th-label">Buy</span></th>
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
                      <span class="catalog-tile catalog-tile--md slb-hero-catalog-clone__flag-tile" aria-hidden="true">{!! getCountryFlag('de') !!}</span>
                      <div class="catalog-site-stack__body">
                        <div class="catalog-site-title-row">
                          <span class="text-dark catalog-site-name">{{ $site['name'] }}</span>
                          <span class="catalog-site-controls">
                            <span class="catalog-site-badges">
                              @if(!empty($site['verified']))
                                <span class="site-chip site-chip--verified site-chip--status">
                                  <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                  <span>Verified</span>
                                </span>
                              @endif
                            </span>
                            <span class="catalog-site-actions">
                              <span class="btn btn-sm btn-link text-secondary p-0 catalog-details-toggle">
                                <span class="catalog-details-toggle__label">Details</span>
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
                        <span class="toggle-cats-btn">+{{ $site['more_cats'] }} more</span>
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
                      <span class="catalog-country__flag" aria-hidden="true">{!! getCountryFlag('de') !!}</span>
                      <span class="catalog-country__name text-muted small">Germany</span>
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
                        <span>Add to cart</span>
                      </span>
                      <div class="catalog-row-actions__secondary">
                        <div class="catalog-row-actions-quiet">
                          <span class="btn-icon-quiet"><i class="fa-regular fa-heart" aria-hidden="true"></i></span>
                        </div>
                        <span class="btn-claim-site">Is this your site?</span>
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
