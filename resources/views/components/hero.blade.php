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
    min-height: min(88vh, 820px);
    overflow: visible;
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
    grid-template-columns: minmax(220px, 0.62fr) minmax(0, 1.85fr);
    gap: 20px;
    align-items: center;
    width: 100%;
    max-width: 1600px;
    margin: 0 auto;
    padding-left: clamp(16px, 4vw, 56px);
    padding-right: 0;
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
    justify-self: end;
    width: 1340px;
    max-width: none;
    zoom: 0.74;
    animation: slbHeroRise 0.9s ease 0.18s both;
    overflow: hidden;
    border-radius: 18px 0 0 0;
    box-shadow: -18px 24px 70px rgba(26, 88, 94, 0.18);
    border: 1px solid rgba(26, 88, 94, 0.1);
    border-right: none;
    background: #f7fafb;
  }

  .slb-hero-catalog-clone {
    pointer-events: none;
    padding: 10px 8px 6px 10px;
    background: transparent;
    width: 100%;
    min-width: 0;
  }

  .slb-hero-catalog-clone .catalog-filters-card .row {
    flex-wrap: nowrap;
  }

  .slb-hero-catalog-clone .catalog-tag-quick {
    flex-wrap: nowrap;
    white-space: nowrap;
  }

  .slb-hero-catalog-clone .catalog-table-scroll {
    display: block !important;
    overflow: visible;
  }

  .slb-hero-catalog-clone .catalog-metric {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    min-width: 3.5rem;
  }

  .slb-hero-catalog-clone .catalog-metric__bar {
    display: block;
    width: 3.25rem;
    max-width: 100%;
    height: 6px;
    border-radius: 999px;
    background: #d5dbe3;
    overflow: hidden;
  }

  .slb-hero-catalog-clone .catalog-metric__fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #3faeb2;
  }

  .slb-hero-catalog-clone .catalog-metric--da .catalog-metric__fill {
    background: #24abe2;
  }

  .slb-hero-catalog-clone .catalog-country {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
  }

  .slb-hero-catalog-clone__flag-tile {
    font-size: 1.15rem;
    background: #e6f5f5;
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
      background: #f7fafb;
      border: 1px solid rgba(26, 88, 94, 0.1);
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
