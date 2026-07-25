@php
    $marketplaceHref = localized_url('marketplace');
    $publisherHref = localized_url('become-a-publisher');
    $catalogPreview = $catalogPreview ?? collect();
    $hasLivePreview = $catalogPreview instanceof \Illuminate\Support\Collection
        ? $catalogPreview->isNotEmpty()
        : ! empty($catalogPreview);
    $previewCountries = collect($hasLivePreview ? $catalogPreview : [])
        ->pluck('country')
        ->filter()
        ->map(fn ($c) => strtolower((string) $c))
        ->unique()
        ->values();
@endphp

<section class="slb-hero">
  <div class="slb-hero-bg" aria-hidden="true"></div>
  <div class="slb-hero-grid" aria-hidden="true"></div>

  <div class="container-fluid slb-hero-inner">
    <div class="slb-hero-copy">
      <div class="slb-hero-brand-stack">
        <img src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}"
             alt="SEOLinkBuildings"
             class="slb-hero-mark">
      </div>

      <h1 class="slb-hero-title">{{ __('messages.hero_support') }}</h1>

      <p class="slb-hero-tagline">{{ __('messages.hero_tagline') }}</p>

      <div class="slb-hero-cta-group">
        <a href="{{ url('/register') }}" class="slb-hero-cta">
          {{ __('messages.get_started') }}
        </a>
        <a href="{{ $publisherHref }}" class="slb-hero-cta-secondary">
          {{ __('messages.nav_become_publisher') }}
        </a>
      </div>

      <a href="{{ $marketplaceHref }}" class="slb-hero-catalog-text">
        {{ __('messages.nav_marketplace') }}
        <i class="fa fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>

    <div class="slb-hero-visual">
      <a href="{{ $marketplaceHref }}" class="slb-hero-catalog-link" aria-label="{{ __('messages.nav_marketplace') }}">
        @if($hasLivePreview)
          <div class="slb-hero-product slb-hero-catalog" role="img" aria-label="Marketplace catalog preview">
            <div class="slb-hero-catalog__chrome">
              <div class="slb-hero-catalog__traffic">
                <span class="slb-hero-catalog__dot" aria-hidden="true"></span>
                <span class="slb-hero-catalog__dot" aria-hidden="true"></span>
                <span class="slb-hero-catalog__dot" aria-hidden="true"></span>
                <span class="slb-hero-catalog__label">Marketplace catalog</span>
              </div>
              <div class="slb-hero-catalog__markets" aria-hidden="true">
                <span class="slb-hero-catalog__chip is-active">All markets</span>
                @foreach($previewCountries->take(6) as $code)
                  <span class="slb-hero-catalog__chip">
                    <span class="slb-hero-catalog__flag">{!! getCountryFlag($code) !!}</span>
                    {{ strtoupper($code === 'gb' || $code === 'uk' ? 'uk' : $code) }}
                  </span>
                @endforeach
              </div>
            </div>

            <div class="slb-hero-catalog__scroll">
              <table class="slb-hero-catalog__table">
                <thead>
                  <tr>
                    <th>Site</th>
                    <th>Category</th>
                    <th>Country</th>
                    <th>DR</th>
                    <th>DA</th>
                    <th>Traffic</th>
                    <th>Price</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($catalogPreview as $index => $site)
                    @php
                      $country = strtolower((string) ($site['country'] ?? ''));
                      $niche = (string) ($site['niche'] ?? 'General');
                      $trafficLabel = $site['traffic_label'] ?? '—';
                    @endphp
                    <tr style="--slb-row: {{ (int) $index }}">
                      <td>
                        <div class="slb-hero-catalog__site">
                          @if(!empty($site['thumb_url']))
                            <img src="{{ $site['thumb_url'] }}" alt="" class="slb-hero-catalog__thumb" width="36" height="36" loading="eager" decoding="async">
                          @else
                            <span class="slb-hero-catalog__thumb slb-hero-catalog__thumb--placeholder" aria-hidden="true">
                              <i class="fa fa-globe"></i>
                            </span>
                          @endif
                          <div class="slb-hero-catalog__site-text">
                            <div class="slb-hero-catalog__domain">{{ $site['domain_masked'] }}</div>
                            <div class="slb-hero-catalog__name">{{ $site['name'] }}</div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span class="slb-hero-catalog__niche">{{ $niche }}</span>
                      </td>
                      <td>
                        <span class="slb-hero-catalog__country">
                          @if($country !== '')
                            <span aria-hidden="true">{!! getCountryFlag($country) !!}</span>
                          @endif
                          <span>{{ $country !== '' ? fullCountry($country) : '—' }}</span>
                        </span>
                      </td>
                      <td>
                        <span class="slb-hero-catalog__metric slb-hero-catalog__metric--dr">
                          <span class="slb-hero-catalog__metric-icon" aria-hidden="true">DR</span>
                          {{ $site['dr'] ?? '—' }}
                        </span>
                      </td>
                      <td>
                        <span class="slb-hero-catalog__metric slb-hero-catalog__metric--da">
                          <span class="slb-hero-catalog__metric-icon" aria-hidden="true">DA</span>
                          {{ $site['da'] ?? '—' }}
                        </span>
                      </td>
                      <td>
                        <span class="slb-hero-catalog__traffic-val">{{ $trafficLabel }}</span>
                      </td>
                      <td class="slb-hero-catalog__price">€{{ number_format((float) ($site['price'] ?? 0), 0) }}</td>
                      <td>
                        <span class="slb-hero-catalog__buy">
                          <i class="fa fa-shopping-cart" aria-hidden="true"></i>
                          Buy
                        </span>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        @else
          <picture>
            <source srcset="{{ asset('assets/img/dashboard.webp') }}" type="image/webp">
            <img
              src="{{ asset('assets/img/dashboard.png') }}"
              alt="SEOLinkBuildings catalog preview"
              class="slb-hero-product"
              width="1200"
              height="518"
              loading="eager"
              decoding="async"
            >
          </picture>
        @endif
      </a>
    </div>
  </div>
</section>

<style>
  .slb-hero {
    position: relative;
    width: 100%;
    margin-top: 0;
    min-height: min(90vh, 860px);
    overflow: hidden;
    display: flex;
    align-items: center;
    padding: 40px 0 0;
    background: var(--grad-hero, linear-gradient(145deg, #e6f5f5 0%, #f7fafb 40%, #ffffff 100%));
  }

  .slb-hero-bg {
    position: absolute;
    inset: 0;
    background: var(--grad-wash-hero,
      radial-gradient(ellipse 58% 52% at 88% 40%, rgba(14, 165, 233, 0.18), transparent 72%),
      radial-gradient(ellipse 42% 48% at 6% 80%, rgba(26, 88, 94, 0.10), transparent 65%));
    pointer-events: none;
  }

  .slb-hero-grid {
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(rgba(var(--brand-primary-rgb, 26, 88, 94), 0.035) 1px, transparent 1px),
      linear-gradient(90deg, rgba(var(--brand-primary-rgb, 26, 88, 94), 0.035) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse 70% 70% at 70% 40%, black, transparent 85%);
    pointer-events: none;
    opacity: 0.9;
  }

  .slb-hero-inner {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: minmax(280px, 0.72fr) minmax(0, 1.55fr);
    gap: 24px;
    align-items: center;
    max-width: 1440px;
    margin: 0 auto;
    padding-left: clamp(20px, 4vw, 56px);
    padding-right: 0;
  }

  .slb-hero-brand-stack {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 1rem;
    animation: slbHeroFade 0.7s ease both;
  }

  .slb-hero-mark {
    height: clamp(56px, 8vw, 84px);
    width: auto;
    max-width: min(560px, 94%);
    object-fit: contain;
    background: transparent;
  }

  .slb-hero-title {
    margin: 0;
    font-family: var(--slb-font-display, 'Sora', sans-serif);
    font-size: clamp(1.65rem, 3.2vw, 2.55rem);
    line-height: 1.15;
    font-weight: 700;
    color: var(--brand-primary, #1a585e);
    letter-spacing: -0.03em;
    max-width: 18ch;
    animation: slbHeroFade 0.7s ease 0.08s both;
  }

  .slb-hero-tagline {
    margin: 0.85rem 0 0;
    font-size: 1.05rem;
    line-height: 1.55;
    color: #4b5563;
    max-width: 36ch;
    animation: slbHeroFade 0.7s ease 0.16s both;
  }

  .slb-hero-cta-group {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 1.75rem;
    animation: slbHeroFade 0.7s ease 0.24s both;
  }

  .slb-hero-cta,
  .slb-hero-cta-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 14px 28px;
    font-size: 0.98rem;
    font-weight: 700;
    border-radius: 12px;
    text-decoration: none;
    transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease, color 0.25s ease, border-color 0.25s ease;
  }

  .slb-hero-cta {
    color: #fff;
    background: var(--brand-primary, #1a585e);
    box-shadow: 0 10px 24px rgba(26, 88, 94, 0.18);
  }

  .slb-hero-cta:hover {
    color: #fff;
    background: var(--brand-primary-deep, #123f42);
    transform: none;
    box-shadow: 0 10px 24px rgba(26, 88, 94, 0.22);
  }

  .slb-hero-cta-secondary {
    color: var(--brand-primary, #1a585e);
    background: rgba(255, 255, 255, 0.72);
    border: 1px solid rgba(26, 88, 94, 0.18);
    backdrop-filter: blur(8px);
  }

  .slb-hero-cta-secondary:hover {
    color: var(--brand-primary, #1a585e);
    border-color: rgba(26, 88, 94, 0.35);
    background: #fff;
    transform: none;
  }

  .slb-hero-catalog-text {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 1.1rem;
    font-size: 0.92rem;
    font-weight: 600;
    color: var(--brand-primary, #1a585e);
    text-decoration: none;
    animation: slbHeroFade 0.7s ease 0.3s both;
  }

  .slb-hero-catalog-text:hover {
    color: var(--brand-primary-soft, #3faeb2);
  }

  .slb-hero-catalog-text i {
    font-size: 0.75rem;
    transition: transform 0.2s ease;
  }

  .slb-hero-catalog-text:hover i {
    transform: translateX(3px);
  }

  .slb-hero-visual {
    position: relative;
    align-self: end;
    width: 100%;
    animation: slbHeroRise 0.9s ease 0.18s both;
  }

  .slb-hero-catalog-link {
    display: block;
    position: relative;
    text-decoration: none;
    color: inherit;
    transform-origin: bottom right;
  }

  .slb-hero-product {
    display: block;
    width: 100%;
    min-height: min(52vh, 480px);
    max-height: min(72vh, 640px);
    object-fit: cover;
    object-position: left top;
    border-radius: 18px 0 0 0;
    box-shadow: -18px 24px 70px rgba(26, 88, 94, 0.18);
    border: 1px solid rgba(26, 88, 94, 0.1);
    border-right: none;
    transition: transform 0.35s ease, box-shadow 0.35s ease;
    background: #fff;
  }

  .slb-hero-catalog {
    display: flex;
    flex-direction: column;
    object-fit: unset;
    overflow: hidden;
    max-height: min(72vh, 640px);
    min-height: min(50vh, 460px);
  }

  .slb-hero-catalog__chrome {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 14px 11px;
    background: linear-gradient(135deg, #1a585e 0%, #2a7a82 55%, #3faeb2 100%);
    border-bottom: 1px solid rgba(26, 88, 94, 0.12);
  }

  .slb-hero-catalog__traffic {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .slb-hero-catalog__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.35);
  }

  .slb-hero-catalog__dot:nth-child(1) { background: #fca5a5; }
  .slb-hero-catalog__dot:nth-child(2) { background: #fcd34d; }
  .slb-hero-catalog__dot:nth-child(3) { background: #86efac; }

  .slb-hero-catalog__label {
    margin-left: 8px;
    font-size: 0.78rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.92);
    letter-spacing: 0.02em;
  }

  .slb-hero-catalog__markets {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .slb-hero-catalog__chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: rgba(255, 255, 255, 0.88);
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.18);
    white-space: nowrap;
  }

  .slb-hero-catalog__chip.is-active {
    color: #1a585e;
    background: #fff;
    border-color: #fff;
  }

  .slb-hero-catalog__flag {
    font-size: 0.85rem;
    line-height: 1;
  }

  .slb-hero-catalog__scroll {
    overflow: auto;
    flex: 1;
  }

  .slb-hero-catalog__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    min-width: 720px;
  }

  .slb-hero-catalog__table thead th {
    text-align: left;
    padding: 11px 12px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    background: #f7fafb;
    border-bottom: 1px solid rgba(26, 88, 94, 0.08);
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 1;
  }

  .slb-hero-catalog__table tbody td {
    padding: 11px 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    color: #1e293b;
    vertical-align: middle;
    white-space: nowrap;
  }

  .slb-hero-catalog__table tbody tr {
    animation: slbHeroRowIn 0.55s ease both;
    animation-delay: calc(0.08s + (var(--slb-row, 0) * 0.06s));
  }

  .slb-hero-catalog__table tbody tr:nth-child(even) {
    background: rgba(247, 250, 251, 0.65);
  }

  .slb-hero-catalog__table tbody tr:last-child td {
    border-bottom: none;
  }

  .slb-hero-catalog__site {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
  }

  .slb-hero-catalog__thumb {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    background: #eef4f5;
    border: 1px solid rgba(26, 88, 94, 0.1);
  }

  .slb-hero-catalog__thumb--placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    font-size: 0.9rem;
  }

  .slb-hero-catalog__site-text {
    min-width: 0;
  }

  .slb-hero-catalog__domain {
    font-weight: 700;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
  }

  .slb-hero-catalog__name {
    font-size: 0.7rem;
    color: #64748b;
    max-width: 18ch;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .slb-hero-catalog__niche {
    display: inline-block;
    max-width: 16ch;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 4px 9px;
    border-radius: 8px;
    font-size: 0.7rem;
    font-weight: 600;
    color: #1a585e;
    background: rgba(63, 174, 178, 0.14);
    border: 1px solid rgba(63, 174, 178, 0.28);
  }

  .slb-hero-catalog__country {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    color: #334155;
  }

  .slb-hero-catalog__metric {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: #0f172a;
  }

  .slb-hero-catalog__metric-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 6px;
    font-size: 0.58rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    color: #fff;
  }

  .slb-hero-catalog__metric--dr .slb-hero-catalog__metric-icon {
    background: #1a585e;
  }

  .slb-hero-catalog__metric--da .slb-hero-catalog__metric-icon {
    background: #3faeb2;
  }

  .slb-hero-catalog__traffic-val {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: #334155;
  }

  .slb-hero-catalog__price {
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: var(--brand-primary, #1a585e);
  }

  .slb-hero-catalog__buy {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 9px;
    font-size: 0.72rem;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #1a585e 0%, #2f8a90 100%);
    box-shadow: 0 4px 12px rgba(26, 88, 94, 0.18);
  }

  .slb-hero-catalog-link:hover .slb-hero-product {
    transform: translateY(-4px);
    box-shadow: -20px 28px 72px rgba(26, 88, 94, 0.22);
  }

  .slb-hero-catalog-link:hover .slb-hero-catalog__chip:not(.is-active) {
    background: rgba(255, 255, 255, 0.2);
  }

  @keyframes slbHeroFade {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  @keyframes slbHeroRise {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
  }

  @keyframes slbHeroRowIn {
    from { opacity: 0; transform: translateX(12px); }
    to { opacity: 1; transform: translateX(0); }
  }

  @media (max-width: 991.98px) {
    .slb-hero {
      min-height: auto;
      padding: 36px 0 0;
    }
    .slb-hero-inner {
      grid-template-columns: 1fr;
      gap: 24px;
      text-align: center;
      padding-right: clamp(20px, 4vw, 56px);
    }
    .slb-hero-brand-stack {
      align-items: center;
    }
    .slb-hero-title,
    .slb-hero-tagline {
      max-width: none;
      margin-left: auto;
      margin-right: auto;
    }
    .slb-hero-cta-group {
      justify-content: center;
    }
    .slb-hero-product {
      min-height: 240px;
      max-height: 420px;
      border-radius: 16px 16px 0 0;
      border-right: 1px solid rgba(26, 88, 94, 0.1);
    }
    .slb-hero-catalog {
      min-height: 280px;
      max-height: 460px;
    }
    .slb-hero-catalog__markets {
      justify-content: flex-start;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .slb-hero-brand-stack,
    .slb-hero-title,
    .slb-hero-tagline,
    .slb-hero-cta-group,
    .slb-hero-catalog-text,
    .slb-hero-visual,
    .slb-hero-product,
    .slb-hero-catalog__table tbody tr {
      animation: none !important;
      transition: none !important;
    }
  }
</style>
