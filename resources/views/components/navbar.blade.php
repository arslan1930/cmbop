@php
  use App\Support\PublicI18n;

  $languages = get_available_locales();
  $currentLocale = public_locale();
  $currentLanguage = $languages[$currentLocale] ?? $languages['en'];
  $showSwitcher = show_public_language_switcher();
  // On English-only auth pages, send logo back to the visitor's remembered public locale
  $homeLocale = $showSwitcher
      ? $currentLocale
      : (class_exists(PublicI18n::class) ? PublicI18n::rememberedPublicLocale(request()) : 'en');
  $homeUrl = localized_url('/', $homeLocale);
  // Auth always English
  $loginUrl = url('/login');
  $registerUrl = url('/register');
@endphp

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top slb-nav">
  <div class="container">

    <a class="navbar-brand fw-bold d-flex align-items-center flex-shrink-0" href="{{ $homeUrl }}" aria-label="SEOLinkBuildings home">
      <img src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}"
           alt="SEOLinkBuildings"
           class="navbar-logo">
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"
            aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarContent">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center flex-wrap flex-lg-nowrap">
        @if($showSwitcher)
          <li class="nav-item">
            <a class="nav-link px-2 px-lg-3" href="{{ localized_url('marketplace') }}">{{ __('messages.nav_marketplace') }}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link px-2 px-lg-3" href="{{ localized_url('pricing') }}">{{ __('messages.nav_pricing') }}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link px-2 px-lg-3" href="{{ localized_url('how-it-works') }}">{{ __('messages.nav_how_it_works') }}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link px-2 px-lg-3" href="{{ localized_url('blog') }}">{{ __('messages.blog') }}</a>
          </li>
          <li class="nav-item">
            <a class="nav-link px-2 px-lg-3" href="{{ localized_url('contact') }}">{{ __('messages.contact') }}</a>
          </li>
        @endif

        @auth
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 text-white navbar-cta-primary" href="{{ auth()->user()->getDashboardRoute() }}">
              {{ __('messages.Dashboard') }}
            </a>
          </li>
          <li class="nav-item">
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="nav-link px-3 mx-lg-2 navbar-cta-outline">
                {{ __('messages.logout') }}
              </button>
            </form>
          </li>
        @else
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 navbar-cta-outline" href="{{ $loginUrl }}">
              {{ __('messages.login') }}
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link text-white px-3 navbar-cta-primary" href="{{ $registerUrl }}">
              {{ __('messages.Sign Up') }}
            </a>
          </li>
        @endauth
      </ul>

      @if($showSwitcher)
        <div class="dropdown ms-lg-2 d-inline-block mt-2 mt-lg-0">
          <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2 navbar-lang-btn"
                  type="button"
                  id="languageDropdown"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                  aria-label="{{ __('messages.language') }}">
            <span class="navbar-lang-flag">{!! $currentLanguage['flag'] !!}</span>
            <span>{{ $currentLanguage['name'] }}</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown" style="min-width: 150px;">
            @foreach($languages as $code => $language)
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2 {{ $code == $currentLocale ? 'active' : '' }}"
                   href="{{ get_language_switcher_url($code) }}"
                   lang="{{ \App\Support\PublicI18n::htmlLang($code) }}">
                  <span class="navbar-lang-flag">{!! $language['flag'] !!}</span>
                  <span>{{ $language['name'] }}</span>
                  @if($code == $currentLocale)
                    <i class="fa fa-check ms-auto" style="font-size: 0.75rem;"></i>
                  @endif
                </a>
              </li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
  </div>
</nav>

<script>
  window.addEventListener('scroll', function () {
    const navbar = document.getElementById('mainNavbar');
    if (!navbar) return;
    const logo = navbar.querySelector('.navbar-logo');
    const wide = window.matchMedia('(min-width: 1400px)').matches;
    const mid = window.matchMedia('(min-width: 992px) and (max-width: 1399.98px)').matches;
    if (window.scrollY > 50) {
      navbar.classList.add('navbar-scrolled');
      if (logo) logo.style.height = mid ? '40px' : (wide ? '52px' : '44px');
    } else {
      navbar.classList.remove('navbar-scrolled');
      if (logo) logo.style.height = mid ? '44px' : (wide ? '64px' : '52px');
    }
  });
</script>

<style>
  :root {
    --public-navbar-height: 88px;
  }

  html {
    overflow-x: clip;
  }

  body {
    padding-top: var(--public-navbar-height);
    overflow-x: clip;
    overflow-y: auto;
  }

  #mainNavbar {
    transition: padding 0.3s ease, box-shadow 0.3s ease;
    padding: 0.75rem 0;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    z-index: 1030;
  }

  #mainNavbar .container {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.75rem;
    max-width: 100%;
  }

  #mainNavbar.navbar-scrolled {
    padding: 0.45rem 0;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
  }

  #mainNavbar .navbar-logo {
    height: 52px;
    width: auto;
    max-width: min(240px, 52vw);
    object-fit: contain;
    background: transparent;
    transition: height 0.3s ease, max-width 0.3s ease;
    flex-shrink: 1;
  }

  #mainNavbar .navbar-brand {
    flex-shrink: 1;
    min-width: 0;
    max-width: min(240px, 52vw);
    overflow: hidden;
  }

  /* Laptop / MacBook: keep wordmark from colliding with nav links */
  @media (min-width: 992px) and (max-width: 1399.98px) {
    :root { --public-navbar-height: 76px; }
    #mainNavbar { padding: 0.45rem 0; }
    #mainNavbar .navbar-logo {
      height: 44px;
      max-width: min(180px, 22vw);
    }
    #mainNavbar .navbar-brand { max-width: min(180px, 22vw); }
    #mainNavbar .nav-link.px-lg-3 { padding-left: 0.45rem !important; padding-right: 0.45rem !important; }
    #mainNavbar .navbar-cta-primary,
    #mainNavbar .navbar-cta-outline { padding-left: 0.7rem !important; padding-right: 0.7rem !important; }
    #mainNavbar .navbar-lang-btn span:not(.navbar-lang-flag) { display: none; }
  }

  @media (min-width: 1400px) {
    :root { --public-navbar-height: 96px; }
    #mainNavbar .navbar-logo {
      height: 64px;
      max-width: min(300px, 30vw);
    }
    #mainNavbar .navbar-brand { max-width: min(300px, 30vw); }
  }

  @media (max-width: 575.98px) {
    :root { --public-navbar-height: 72px; }
    #mainNavbar .navbar-logo {
      height: 44px;
      max-width: min(200px, 56vw);
    }
    #mainNavbar .navbar-brand { max-width: min(200px, 56vw); }
  }

  #mainNavbar .navbar-cta-primary,
  #mainNavbar .navbar-cta-outline,
  #mainNavbar .navbar-lang-btn {
    white-space: nowrap;
  }

  .navbar-cta-primary {
    background-color: var(--brand-primary, #1a585e);
    border-radius: 999px;
    font-weight: 600;
    transition: background-color 150ms ease, box-shadow 150ms ease;
  }

  .navbar-cta-outline {
    border: 1px solid var(--brand-primary-border, #b8e4e4);
    border-radius: 999px;
    font-weight: 600;
    color: var(--brand-primary, #1a585e);
    background: none;
    cursor: pointer;
    transition: background-color 150ms ease, border-color 150ms ease;
  }

  .navbar-lang-btn {
    border: 1px solid #dee2e6;
    border-radius: 999px;
    padding: 0.375rem 0.75rem;
  }

  .navbar-lang-flag { font-size: 1.2rem; }

  .navbar-nav .nav-link {
    transition: color 150ms ease, background 150ms ease;
    white-space: nowrap;
  }

  .navbar-nav .nav-link:hover {
    color: var(--brand-primary, #1a585e) !important;
    background-color: rgba(26, 88, 94, 0.06) !important;
  }

  .navbar-nav .nav-link[href*="/login"]:hover,
  .navbar-nav form button.nav-link:hover {
    color: var(--brand-primary, #1a585e) !important;
    border-color: var(--brand-primary-soft, #3faeb2) !important;
    background-color: transparent !important;
  }

  .navbar-nav .nav-link[href*="/register"]:hover,
  .navbar-nav .nav-link[href*="dashboard"]:hover {
    background-color: var(--brand-primary-deep, #123f42) !important;
    color: #fff !important;
  }

  .dropdown-item.active {
    background-color: var(--hover-overlay-strong, rgba(15, 23, 42, 0.10));
    color: var(--brand-primary, #1a585e);
  }

  .dropdown-item:active { background-color: var(--hover-overlay-strong, rgba(15, 23, 42, 0.10)); }

  .dropdown-item:hover {
    background-color: var(--hover-overlay, rgba(15, 23, 42, 0.06));
    color: var(--brand-ink, #1e293b);
  }

  @media (max-width: 991.98px) {
    :root { --public-navbar-height: 76px; }
    #mainNavbar .navbar-collapse {
      max-height: min(70vh, 520px);
      overflow-y: auto;
      padding-bottom: 0.5rem;
    }
  }
</style>
