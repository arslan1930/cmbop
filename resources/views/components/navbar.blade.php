@php
  use App\Support\PublicI18n;

  $languages = get_available_locales();
  $currentLocale = public_locale();
  $currentLanguage = $languages[$currentLocale] ?? $languages['en'];
  $showSwitcher = show_public_language_switcher();
  // On English-only auth pages, send logo back to the visitor's remembered public locale
  $homeLocale = $showSwitcher
      ? $currentLocale
      : PublicI18n::rememberedPublicLocale(request());
  $homeUrl = localized_url('/', $homeLocale);
  // Auth always English
  $loginUrl = url('/login');
  $registerUrl = url('/register');
@endphp

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top slb-nav">
  <div class="container">

    <a class="navbar-brand fw-bold d-flex align-items-center" href="{{ $homeUrl }}" aria-label="SEOLinkBuildings home">
      <img src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}"
           alt="SEOLinkBuildings"
           class="navbar-logo">
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"
            aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarContent">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center flex-wrap">
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
                   hreflang="{{ $code }}">
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
    if (window.scrollY > 50) {
      navbar.classList.add('navbar-scrolled');
      if (logo) logo.style.height = '36px';
    } else {
      navbar.classList.remove('navbar-scrolled');
      if (logo) logo.style.height = '42px';
    }
  });
</script>

<style>
  :root {
    --public-navbar-height: 88px;
  }

  body {
    padding-top: var(--public-navbar-height);
  }

  #mainNavbar {
    transition: padding 0.3s ease, box-shadow 0.3s ease;
    padding: 1rem 0;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    z-index: 1030;
  }

  #mainNavbar.navbar-scrolled {
    padding: 0.6rem 0;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
  }

  #mainNavbar .navbar-logo {
    height: 42px;
    width: auto;
    max-width: min(220px, 58vw);
    object-fit: contain;
    transition: height 0.3s ease;
  }

  .navbar-cta-primary {
    background-color: #4ECDCB;
    border-radius: 0.5rem;
    font-weight: 500;
    transition: all 0.3s;
  }

  .navbar-cta-outline {
    border: 1px solid #4ECDCB;
    border-radius: 0.5rem;
    font-weight: 500;
    color: #4ECDCB;
    background: none;
    cursor: pointer;
    transition: all 0.3s;
  }

  .navbar-lang-btn {
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    padding: 0.375rem 0.75rem;
  }

  .navbar-lang-flag { font-size: 1.2rem; }

  .navbar-nav .nav-link {
    transition: color 0.3s ease, background 0.3s ease;
    white-space: nowrap;
  }

  .navbar-nav .nav-link:hover {
    color: #4ECDCB !important;
    background-color: transparent !important;
  }

  .navbar-nav .nav-link[href*="/login"]:hover,
  .navbar-nav form button.nav-link:hover {
    color: #2a9a95 !important;
    border-color: #2a9a95 !important;
    background-color: transparent !important;
  }

  .navbar-nav .nav-link[href*="/register"]:hover,
  .navbar-nav .nav-link[href*="dashboard"]:hover {
    background-color: #3aaeb2 !important;
    color: #fff !important;
  }

  .dropdown-item.active {
    background-color: #4ECDCB;
    color: white;
  }

  .dropdown-item:active { background-color: #3aaeb2; }

  .dropdown-item:hover {
    background-color: rgba(78, 205, 203, 0.1);
    color: #4ECDCB;
  }

  @media (max-width: 991.98px) {
    :root { --public-navbar-height: 76px; }
  }
</style>
