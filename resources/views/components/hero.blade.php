@php
    $catalogHref = Route::has('advertiser.catalog')
        ? route('advertiser.catalog')
        : url('/advertiser/catalog');
@endphp

<section class="slb-hero">
  <div class="slb-hero-bg" aria-hidden="true"></div>

  <div class="container-fluid slb-hero-inner">
    <div class="slb-hero-copy">
      <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings" class="slb-hero-brand">

      <h1 class="slb-hero-title">{{ __('messages.hero_title') }}</h1>

      <p class="slb-hero-tagline">{{ __('messages.hero_tagline') }}</p>

      <a href="{{ url('/register') }}" class="slb-hero-cta">
        {{ __('messages.get_started') }}
      </a>
    </div>

    <div class="slb-hero-visual">
      <a href="{{ $catalogHref }}" class="slb-hero-catalog-link" aria-label="Open the SEOLinkBuildings catalog">
        <img
          src="{{ asset('assets/img/dashboard.png') }}"
          alt="SEOLinkBuildings catalog preview"
          class="slb-hero-product"
          loading="eager"
          decoding="async"
        >
        <span class="slb-hero-catalog-hint">
          <i class="fa fa-external-link-alt" aria-hidden="true"></i>
          Explore catalog
        </span>
      </a>
    </div>
  </div>
</section>

<style>
  .slb-hero {
    position: relative;
    width: 100%;
    /* Public layout already offsets the fixed navbar via body padding-top */
    margin-top: 0;
    min-height: min(92vh, 900px);
    overflow: hidden;
    display: flex;
    align-items: center;
    padding: 40px 0 0;
    background: linear-gradient(135deg, #e8f7f7 0%, #f4fafb 42%, #ffffff 100%);
  }

  .slb-hero-bg {
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 60% 55% at 88% 42%, rgba(78, 205, 203, 0.26), transparent 72%),
      radial-gradient(ellipse 40% 45% at 8% 78%, rgba(11, 98, 102, 0.08), transparent 65%);
    pointer-events: none;
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

  .slb-hero-brand {
    height: 48px;
    width: auto;
    margin-bottom: 1.25rem;
    animation: slbHeroFade 0.7s ease both;
  }

  .slb-hero-title {
    margin: 0;
    font-size: clamp(2rem, 3.8vw, 3.15rem);
    line-height: 1.12;
    font-weight: 800;
    color: #0b6266;
    letter-spacing: -0.03em;
    max-width: 16ch;
    animation: slbHeroFade 0.7s ease 0.08s both;
  }

  .slb-hero-tagline {
    margin: 1rem 0 0;
    font-size: 1.05rem;
    line-height: 1.55;
    color: #4b5563;
    max-width: 34ch;
    animation: slbHeroFade 0.7s ease 0.16s both;
  }

  .slb-hero-cta {
    display: inline-flex;
    align-items: center;
    margin-top: 1.75rem;
    padding: 14px 32px;
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
    background: #0b6266;
    border-radius: 10px;
    text-decoration: none;
    box-shadow: 0 10px 24px rgba(11, 98, 102, 0.22);
    transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
    animation: slbHeroFade 0.7s ease 0.24s both;
  }

  .slb-hero-cta:hover {
    color: #fff;
    background: #3aaeb2;
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(58, 174, 178, 0.28);
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

  .slb-hero-catalog-hint {
    position: absolute;
    left: 18px;
    bottom: 18px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    background: rgba(11, 98, 102, 0.92);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    backdrop-filter: blur(8px);
    box-shadow: 0 8px 20px rgba(11, 98, 102, 0.25);
    opacity: 1;
    transition: transform 0.25s ease, background 0.25s ease;
  }

  .slb-hero-catalog-link:hover .slb-hero-catalog-hint {
    background: #0b6266;
    transform: translateY(-2px);
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
    .slb-hero-title,
    .slb-hero-tagline {
      max-width: none;
      margin-left: auto;
      margin-right: auto;
    }
    .slb-hero-brand {
      margin-left: auto;
      margin-right: auto;
    }
    .slb-hero-product {
      min-height: 280px;
      max-height: 420px;
      border-radius: 16px 16px 0 0;
      border-right: 1px solid rgba(11, 98, 102, 0.1);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .slb-hero-brand,
    .slb-hero-title,
    .slb-hero-tagline,
    .slb-hero-cta,
    .slb-hero-visual,
    .slb-hero-product {
      animation: none !important;
      transition: none !important;
    }
  }
</style>
