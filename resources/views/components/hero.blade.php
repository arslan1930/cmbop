@php
    $catalogHref = Route::has('advertiser.catalog')
        ? route('advertiser.catalog')
        : url('/advertiser/catalog');
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
        <h1 class="slb-hero-title visually-hidden">{{ __('messages.hero_title') }}</h1>
      </div>

      <p class="slb-hero-support">{{ __('messages.hero_support') }}</p>

      <p class="slb-hero-tagline">{{ __('messages.hero_tagline') }}</p>

      <div class="slb-hero-cta-group">
        <a href="{{ url('/register') }}" class="slb-hero-cta">
          {{ __('messages.get_started') }}
        </a>
        <a href="{{ $publisherHref }}" class="slb-hero-cta-secondary">
          {{ __('messages.nav_become_publisher') }}
        </a>
      </div>

      <a href="{{ $catalogHref }}" class="slb-hero-catalog-text">
        {{ __('messages.nav_marketplace') }}
        <i class="fa fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>

    <div class="slb-hero-visual">
      <a href="{{ $catalogHref }}" class="slb-hero-catalog-link" aria-label="{{ __('messages.nav_marketplace') }}">
        <img
          src="{{ asset('assets/img/dashboard.png') }}"
          alt="SEOLinkBuildings catalog preview"
          class="slb-hero-product"
          loading="eager"
          decoding="async"
        >
      </a>
    </div>
  </div>
</section>

<style>
  .slb-hero {
    position: relative;
    width: 100%;
    margin-top: 0;
    min-height: min(92vh, 900px);
    overflow: hidden;
    display: flex;
    align-items: center;
    padding: 40px 0 0;
    background: linear-gradient(145deg, #e7f6f6 0%, #f5fbfb 38%, #ffffff 100%);
  }

  .slb-hero-bg {
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 58% 52% at 88% 40%, rgba(78, 205, 203, 0.28), transparent 72%),
      radial-gradient(ellipse 42% 48% at 6% 80%, rgba(11, 98, 102, 0.1), transparent 65%);
    pointer-events: none;
  }

  .slb-hero-grid {
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(rgba(11, 98, 102, 0.035) 1px, transparent 1px),
      linear-gradient(90deg, rgba(11, 98, 102, 0.035) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse 70% 70% at 70% 40%, black, transparent 85%);
    pointer-events: none;
    opacity: 0.9;
  }

  .slb-hero-inner {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: minmax(280px, 0.78fr) minmax(0, 1.45fr);
    gap: 28px;
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
    height: clamp(48px, 7vw, 72px);
    width: auto;
    max-width: min(420px, 92%);
    object-fit: contain;
  }

  .slb-hero-title {
    margin: 0;
    font-family: var(--slb-font-display, 'Sora', sans-serif);
    font-size: clamp(2.35rem, 4.6vw, 3.75rem);
    line-height: 1.05;
    font-weight: 800;
    color: #0b6266;
    letter-spacing: -0.04em;
  }

  .slb-hero-support {
    margin: 0;
    font-family: var(--slb-font-display, 'Sora', sans-serif);
    font-size: clamp(1.15rem, 2vw, 1.45rem);
    line-height: 1.35;
    font-weight: 600;
    color: #134e4a;
    max-width: 22ch;
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
    background: #0b6266;
    box-shadow: 0 10px 24px rgba(11, 98, 102, 0.22);
  }

  .slb-hero-cta:hover {
    color: #fff;
    background: #3aaeb2;
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(58, 174, 178, 0.28);
  }

  .slb-hero-cta-secondary {
    color: #0b6266;
    background: rgba(255, 255, 255, 0.72);
    border: 1px solid rgba(11, 98, 102, 0.18);
    backdrop-filter: blur(8px);
  }

  .slb-hero-cta-secondary:hover {
    color: #0b6266;
    border-color: rgba(11, 98, 102, 0.35);
    background: #fff;
    transform: translateY(-2px);
  }

  .slb-hero-catalog-text {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 1.1rem;
    font-size: 0.92rem;
    font-weight: 600;
    color: #0b6266;
    text-decoration: none;
    animation: slbHeroFade 0.7s ease 0.3s both;
  }

  .slb-hero-catalog-text:hover {
    color: #3aaeb2;
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
    min-height: min(68vh, 620px);
    max-height: min(78vh, 720px);
    object-fit: cover;
    object-position: left top;
    border-radius: 18px 0 0 0;
    box-shadow: -18px 24px 70px rgba(11, 98, 102, 0.22);
    border: 1px solid rgba(11, 98, 102, 0.1);
    border-right: none;
    transition: transform 0.35s ease, box-shadow 0.35s ease;
  }

  .slb-hero-catalog-link:hover .slb-hero-product {
    transform: translateY(-6px) scale(1.01);
    box-shadow: -22px 30px 80px rgba(11, 98, 102, 0.28);
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
    .slb-hero-support,
    .slb-hero-tagline {
      max-width: none;
      margin-left: auto;
      margin-right: auto;
    }
    .slb-hero-cta-group {
      justify-content: center;
    }
    .slb-hero-product {
      min-height: 280px;
      max-height: 420px;
      border-radius: 16px 16px 0 0;
      border-right: 1px solid rgba(11, 98, 102, 0.1);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .slb-hero-brand-stack,
    .slb-hero-support,
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
