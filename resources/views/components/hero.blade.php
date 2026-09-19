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

    @include('components.hero-catalog-preview')
  </div>
</section>

<style>
  .slb-hero {
    position: relative;
    width: 100%;
    margin-top: 0;
    min-height: auto;
    overflow: visible;
    display: flex;
    align-items: center;
    padding: 52px 0 56px;
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
    grid-template-columns: minmax(280px, 400px) minmax(0, 1fr);
    gap: 32px;
    align-items: center;
    width: 100%;
    max-width: 1280px;
    margin: 0 auto;
    padding-left: clamp(20px, 3.5vw, 40px);
    padding-right: clamp(20px, 3.5vw, 40px);
    min-width: 0;
  }

  body:has(.locale-suggest-banner) .slb-hero {
    padding-top: 56px;
  }

  .slb-hero-copy {
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
    height: clamp(44px, 6vw, 64px);
    width: auto;
    max-width: min(420px, 94%);
    object-fit: contain;
    background: transparent;
    flex-shrink: 0;
  }

  .slb-hero-title {
    margin: 0;
    font-family: var(--slb-font-display, 'Sora', sans-serif);
    font-size: clamp(1.55rem, 2.6vw, 2.15rem);
    line-height: 1.18;
    font-weight: 700;
    color: var(--brand-primary, #1a585e);
    letter-spacing: -0.015em;
    word-spacing: 0.04em;
    max-width: 32ch;
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
    flex-wrap: nowrap;
    gap: 12px;
    margin-top: 1.75rem;
    animation: slbHeroFade 0.7s ease 0.24s both;
  }

  .slb-hero-cta,
  .slb-hero-cta-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 22px;
    font-size: 0.92rem;
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
    align-self: center;
    justify-self: stretch;
    width: 100%;
    max-width: 100%;
    zoom: 1;
    animation: slbHeroRise 0.9s ease 0.18s both;
    overflow-x: auto;
    overflow-y: hidden;
    border-radius: 18px;
    box-shadow: 0 22px 56px rgba(15, 45, 60, 0.16);
    border: 1px solid rgba(26, 88, 94, 0.08);
    background: #fff;
  }

  .slb-hero-catalog-clone {
    pointer-events: none;
    padding: 0;
    background: #fff;
    width: 100%;
    min-width: 0;
    overflow: visible;
  }

  .slb-hero-shot-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
    font-size: 10px;
    line-height: 1.2;
    color: #334155;
    margin: 0;
  }

  .slb-hero-shot-table thead th {
    background: #3b82f6;
    color: #fff;
    font-size: 8.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 8px 6px;
    text-align: center;
    border: 0;
  }

  .slb-hero-shot-table thead th.is-site {
    text-align: left;
    padding-left: 12px;
    border-radius: 16px 0 0 0;
  }

  .slb-hero-shot-table thead th:last-child {
    border-radius: 0 16px 0 0;
  }

  .slb-hero-shot-table tbody td {
    padding: 7px 5px;
    border-bottom: 1px solid #eef2f6;
    vertical-align: middle;
    text-align: center;
    white-space: nowrap;
    background: #fff;
  }

  .slb-hero-shot-table tbody tr:last-child td {
    border-bottom: 0;
  }

  .slb-hero-shot-table td.is-site {
    text-align: left;
    padding-left: 12px;
    min-width: 96px;
  }

  .slb-hero-shot-domain {
    display: inline-block;
    font-weight: 600;
    color: #64748b;
    max-width: 92px;
    overflow: hidden;
  }

  .slb-hero-shot-icons {
    display: inline-flex;
    gap: 4px;
    margin-left: 6px;
    color: #94a3b8;
    font-size: 9px;
  }

  .slb-hero-shot-table td.is-niche {
    text-align: left;
    white-space: normal;
    min-width: 96px;
    max-width: 128px;
    font-size: 9.5px;
    line-height: 1.3;
    color: #64748b;
  }

  .slb-hero-shot-table td.is-niche span {
    display: block;
  }

  .slb-hero-shot-more {
    color: #0ea5e9;
    font-weight: 600;
  }

  .slb-hero-shot-table td.is-country {
    text-align: left;
  }

  .slb-hero-shot-flag {
    display: inline-block;
    margin-right: 4px;
    font-size: 12px;
  }

  .slb-hero-shot-dr {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 16px;
    padding: 0 4px;
    border-radius: 3px;
    background: #2563eb;
    color: #fff;
    font-weight: 700;
    font-size: 9px;
  }

  .slb-hero-shot-table td.is-metric {
    font-weight: 600;
    color: #334155;
  }

  .slb-hero-shot-table td.is-metric i {
    color: #f59e0b;
    font-size: 9px;
    margin-right: 2px;
  }

  .slb-hero-shot-sponsored {
    color: #16a34a;
    font-weight: 600;
    font-size: 9.5px;
  }

  .slb-hero-shot-table td.is-price {
    font-weight: 700;
    color: #0f172a;
  }

  .slb-hero-shot-table td.is-action {
    white-space: nowrap;
  }

  .slb-hero-shot-buy {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 8px;
    border-radius: 6px;
    background: #22c55e;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
  }

  .slb-hero-shot-fav {
    display: inline-flex;
    margin-left: 6px;
    color: #94a3b8;
    font-size: 11px;
  }

  .slb-hero-catalog-hit {
    position: absolute;
    inset: 0;
    z-index: 4;
    text-decoration: none;
  }

  /* Clip the wide catalog preview inside the hero only.
     overflow-x:clip on #main-content makes a nested scrollport and hides
     features / pricing / footer below the first viewport. */
  body:has(.slb-hero-catalog-clone) {
    overflow-x: hidden;
    scrollbar-gutter: auto;
  }
  body:has(.slb-hero-catalog-clone) #main-content,
  body:has(.slb-hero-catalog-clone) #content {
    overflow-x: visible;
    overflow-y: visible;
  }

  @keyframes slbHeroFade {
    from { opacity: 1; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
  }

  @keyframes slbHeroRise {
    from { opacity: 1; transform: translateY(8px); }
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
    /*
     * Stacked hero: shrinking the full dashboard to ~360px makes metrics
     * unreadable. Keep a readable image width and pan inside the visual
     * (page itself must not grow horizontally).
     */
    .slb-hero-visual {
      width: 100%;
      max-width: 100%;
      zoom: 1;
      justify-self: stretch;
      align-self: stretch;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      overscroll-behavior-x: contain;
      border-radius: 16px;
      box-shadow: 0 18px 48px rgba(26, 88, 94, 0.14);
      background: #fff;
      border: 1px solid rgba(26, 88, 94, 0.08);
      scrollbar-width: thin;
    }
    .slb-hero-catalog-clone {
      width: min(920px, 235vw);
      min-width: 720px;
      max-width: none;
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
    .slb-hero-visual {
      border-radius: 14px;
    }
    .slb-hero-catalog-clone {
      width: min(860px, 230vw);
      min-width: 680px;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .slb-hero-brand-stack,
    .slb-hero-title,
    .slb-hero-tagline,
    .slb-hero-cta-group,
    .slb-hero-catalog-text,
    .slb-hero-visual,
    .slb-hero-catalog-clone {
      animation: none !important;
      transition: none !important;
    }
  }
</style>
