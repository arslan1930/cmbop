@php
    $marketplaceHref = localized_url('marketplace');
    $publisherHref = localized_url('become-a-publisher');
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
        <picture>
          <source srcset="{{ asset('assets/img/dashboard.webp') }}" type="image/webp">
          <img
            src="{{ asset('assets/img/dashboard.png') }}"
            alt="{{ __('messages.hero_product_alt') }}"
            class="slb-hero-product"
            width="1200"
            height="518"
            loading="eager"
            decoding="async"
          >
        </picture>
      </a>
    </div>
  </div>
</section>

<style>
  .slb-hero {
    position: relative;
    width: 100%;
    margin-top: 0;
    min-height: min(88vh, 820px);
    overflow-x: clip;
    overflow-y: visible;
    display: flex;
    align-items: center;
    padding: 28px 0 0;
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
    grid-template-columns: minmax(240px, 0.78fr) minmax(0, 1.45fr);
    gap: 28px;
    align-items: center;
    width: 100%;
    max-width: 1440px;
    margin: 0 auto;
    padding-left: clamp(16px, 4vw, 56px);
    padding-right: 0;
    min-width: 0;
  }

  .slb-hero-copy,
  .slb-hero-visual {
    min-width: 0;
    max-width: 100%;
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
    flex-shrink: 0;
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
    white-space: nowrap;
    max-width: 100%;
    transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease, color 0.25s ease, border-color 0.25s ease;
  }

  @media (max-width: 399.98px) {
    .slb-hero-cta,
    .slb-hero-cta-secondary {
      white-space: normal;
      text-align: center;
      line-height: 1.25;
    }
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
    max-height: min(68vh, 620px);
    object-fit: cover;
    object-position: left top;
    border-radius: 18px 0 0 0;
    box-shadow: -18px 24px 70px rgba(26, 88, 94, 0.18);
    border: 1px solid rgba(26, 88, 94, 0.1);
    border-right: none;
    transition: transform 0.35s ease, box-shadow 0.35s ease;
    background: #fff;
  }

  .slb-hero-live-catalog {
    display: flex;
    flex-direction: column;
    object-fit: unset;
    overflow: hidden;
    max-height: min(68vh, 620px);
    min-height: min(48vh, 440px);
  }

  .slb-hero-live-catalog__chrome {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 14px;
    background: linear-gradient(180deg, #f7fafb 0%, #eef4f5 100%);
    border-bottom: 1px solid rgba(26, 88, 94, 0.1);
  }

  .slb-hero-live-catalog__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #cbd5e1;
  }

  .slb-hero-live-catalog__dot:nth-child(1) { background: #f87171; }
  .slb-hero-live-catalog__dot:nth-child(2) { background: #fbbf24; }
  .slb-hero-live-catalog__dot:nth-child(3) { background: #34d399; }

  .slb-hero-live-catalog__label {
    margin-left: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    color: #64748b;
    letter-spacing: 0.01em;
  }

  .slb-hero-live-catalog__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    flex: 1;
  }

  .slb-hero-live-catalog__table thead th {
    text-align: left;
    padding: 10px 12px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid rgba(26, 88, 94, 0.08);
    white-space: nowrap;
  }

  .slb-hero-live-catalog__table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.18);
    color: #1e293b;
    vertical-align: middle;
    white-space: nowrap;
  }

  .slb-hero-live-catalog__table tbody tr:last-child td {
    border-bottom: none;
  }

  .slb-hero-live-catalog__site {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
  }

  .slb-hero-live-catalog__thumb {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    background: #eef4f5;
    border: 1px solid rgba(26, 88, 94, 0.1);
  }

  .slb-hero-live-catalog__thumb--placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    font-size: 0.9rem;
  }

  .slb-hero-live-catalog__name {
    font-weight: 600;
    color: #0f172a;
    max-width: 18ch;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .slb-hero-live-catalog__domain {
    font-size: 0.72rem;
    color: #64748b;
  }

  .slb-hero-live-catalog__country {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    color: #334155;
  }

  .slb-hero-live-catalog__price {
    font-weight: 700;
    color: var(--brand-primary, #1a585e);
  }

  .slb-hero-catalog-link:hover .slb-hero-product {
    transform: translateY(-4px);
    box-shadow: -20px 28px 72px rgba(26, 88, 94, 0.22);
  }

  @keyframes slbHeroFade {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  @keyframes slbHeroRise {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
  }

  @media (max-width: 991.98px) {
    .slb-hero {
      min-height: auto;
      padding: 20px 0 0;
    }
    .slb-hero-inner {
      grid-template-columns: 1fr;
      gap: 20px;
      text-align: center;
      padding-left: clamp(16px, 4vw, 32px);
      padding-right: clamp(16px, 4vw, 32px);
    }
    .slb-hero-brand-stack {
      align-items: center;
    }
    .slb-hero-mark {
      height: clamp(48px, 12vw, 72px);
      max-width: min(420px, 88%);
    }
    .slb-hero-title,
    .slb-hero-tagline {
      max-width: 34ch;
      margin-left: auto;
      margin-right: auto;
    }
    .slb-hero-title {
      font-size: clamp(1.45rem, 6.2vw, 2.1rem);
    }
    .slb-hero-cta-group {
      justify-content: center;
    }
    /* Stacked hero: show the full catalog preview — cover was cropping metrics */
    .slb-hero-product {
      min-height: 0;
      max-height: none;
      height: auto;
      aspect-ratio: 1200 / 518;
      width: 100%;
      object-fit: contain;
      object-position: center top;
      border-radius: 16px;
      border-right: 1px solid rgba(26, 88, 94, 0.1);
      box-shadow: 0 18px 48px rgba(26, 88, 94, 0.14);
    }
    .slb-hero-visual {
      width: 100%;
      max-width: 100%;
      overflow: visible;
      align-self: stretch;
    }
    .slb-hero-catalog-link {
      transform-origin: center bottom;
    }
    .slb-hero-live-catalog {
      min-height: 0;
      max-height: none;
      height: auto;
      width: 100%;
      max-width: 100%;
      overflow: visible;
      border-radius: 16px;
      aspect-ratio: auto;
    }
  }

  @media (max-width: 575.98px) {
    .slb-hero {
      padding-top: 12px;
    }
    .slb-hero-cta-group {
      flex-direction: column;
      align-items: stretch;
    }
    .slb-hero-cta,
    .slb-hero-cta-secondary {
      width: 100%;
    }
    .slb-hero-product {
      border-radius: 14px;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .slb-hero-brand-stack,
    .slb-hero-title,
    .slb-hero-tagline,
    .slb-hero-cta-group,
    .slb-hero-catalog-text,
    .slb-hero-visual,
    .slb-hero-product {
      animation: none !important;
      transition: none !important;
    }
  }
</style>
