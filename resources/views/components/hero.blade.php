<section class="slb-hero">
  <div class="slb-hero-bg" aria-hidden="true"></div>

  <div class="container slb-hero-inner">
    <div class="slb-hero-copy">
      <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings" class="slb-hero-brand">

      <h1 class="slb-hero-title">{{ __('messages.hero_title') }}</h1>

      <p class="slb-hero-tagline">{{ __('messages.hero_tagline') }}</p>

      <a href="{{ localized_url('register') }}" class="slb-hero-cta">
        {{ __('messages.get_started') }}
      </a>
    </div>

    <div class="slb-hero-visual">
      <img
        src="{{ asset('assets/img/dashboard.png') }}"
        alt="SEOLinkBuildings catalog dashboard"
        class="slb-hero-product"
        loading="eager"
      >
    </div>
  </div>
</section>

<style>
  .slb-hero {
    position: relative;
    width: 100%;
    margin-top: 80px;
    min-height: min(88vh, 820px);
    overflow: hidden;
    display: flex;
    align-items: center;
    padding: 48px 0 56px;
    background: linear-gradient(135deg, #e8f7f7 0%, #f4fafb 42%, #ffffff 100%);
  }

  .slb-hero-bg {
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 55% 50% at 85% 40%, rgba(78, 205, 203, 0.22), transparent 70%),
      radial-gradient(ellipse 40% 45% at 10% 80%, rgba(11, 98, 102, 0.08), transparent 65%);
    pointer-events: none;
  }

  .slb-hero-inner {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr);
    gap: 40px;
    align-items: center;
    max-width: 1200px;
  }

  .slb-hero-brand {
    height: 48px;
    width: auto;
    margin-bottom: 1.25rem;
    animation: slbHeroFade 0.7s ease both;
  }

  .slb-hero-title {
    margin: 0;
    font-size: clamp(2rem, 4vw, 3.1rem);
    line-height: 1.15;
    font-weight: 800;
    color: #0b6266;
    letter-spacing: -0.03em;
    max-width: 18ch;
    animation: slbHeroFade 0.7s ease 0.08s both;
  }

  .slb-hero-tagline {
    margin: 1rem 0 0;
    font-size: 1.05rem;
    line-height: 1.55;
    color: #4b5563;
    max-width: 36ch;
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
    animation: slbHeroRise 0.9s ease 0.18s both;
  }

  .slb-hero-product {
    display: block;
    width: 100%;
    height: auto;
    border-radius: 0;
    box-shadow: 0 28px 60px rgba(11, 98, 102, 0.18);
    border: 1px solid rgba(11, 98, 102, 0.08);
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
      padding: 36px 0 40px;
    }
    .slb-hero-inner {
      grid-template-columns: 1fr;
      gap: 28px;
      text-align: center;
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
  }

  @media (prefers-reduced-motion: reduce) {
    .slb-hero-brand,
    .slb-hero-title,
    .slb-hero-tagline,
    .slb-hero-cta,
    .slb-hero-visual {
      animation: none !important;
    }
  }
</style>
