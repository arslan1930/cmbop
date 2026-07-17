@php
  $languages = [
    'en' => ['name' => 'English', 'flag' => '🇬🇧'],
    'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪'],
    'fr' => ['name' => 'Français', 'flag' => '🇫🇷'],
    'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱'],
  ];

  // Get locale from URL segment
  $segments = request()->segments();
  $availableLocales = ['de', 'fr', 'nl'];
  $currentLocale = 'en';

  if (! empty($segments) && in_array($segments[0], $availableLocales, true)) {
    $currentLocale = $segments[0];
  }

  $currentLanguage = $languages[$currentLocale] ?? $languages['en'];

  // Build path without locale for URL switching
  $pathWithoutLocale = '';
  if (! empty($segments)) {
    if (in_array($segments[0], $availableLocales, true)) {
      $pathWithoutLocale = implode('/', array_slice($segments, 1));
    } else {
      $pathWithoutLocale = implode('/', $segments);
    }
  }
@endphp

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top">
  <div class="container">

    <!-- Logo -->
    <a class="navbar-brand fw-bold d-flex align-items-center" href="{{ $currentLocale == 'en' ? '/' : '/' . $currentLocale }}">
      <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings" class="navbar-logo">
    </a>

    <!-- Toggle Button for Mobile -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"
            aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Navbar Links -->
    <div class="collapse navbar-collapse" id="navbarContent">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        @auth
          <!-- Dashboard Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 text-white navbar-cta-primary" href="{{ auth()->user()->getDashboardRoute() }}">
              {{ __('messages.Dashboard') }}
            </a>
          </li>

          <!-- Logout Button -->
          <li class="nav-item">
            <form method="POST" action="{{ $currentLocale == 'en' ? route('logout') : '/' . $currentLocale . '/logout' }}">
              @csrf
              <button type="submit" class="nav-link px-3 mx-lg-2 navbar-cta-outline">
                {{ __('messages.logout') }}
              </button>
            </form>
          </li>
        @else
          <!-- Login Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 navbar-cta-outline" href="{{ $currentLocale == 'en' ? '/login' : '/' . $currentLocale . '/login' }}">
              {{ __('messages.login') }}
            </a>
          </li>

          <!-- Sign Up Button -->
          <li class="nav-item">
            <a class="nav-link text-white px-3 navbar-cta-primary" href="{{ $currentLocale == 'en' ? '/register' : '/' . $currentLocale . '/register' }}">
              {{ __('messages.Sign Up') }}
            </a>
          </li>
        @endauth
      </ul>

      <!-- Language Switcher Dropdown -->
      <div class="dropdown ms-lg-2 d-inline-block">
        <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2 navbar-lang-btn"
                type="button"
                id="languageDropdown"
                data-bs-toggle="dropdown"
                aria-expanded="false">
          <span class="navbar-lang-flag">{!! $currentLanguage['flag'] !!}</span>
          <span>{{ $currentLanguage['name'] }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown" style="min-width: 150px;">
          @foreach($languages as $code => $language)
            @php
              if ($code == 'en') {
                $switchUrl = $pathWithoutLocale ? '/' . $pathWithoutLocale : '/';
              } else {
                $switchUrl = $pathWithoutLocale ? '/' . $code . '/' . $pathWithoutLocale : '/' . $code;
              }
              $switchUrl = str_replace('//', '/', $switchUrl);
            @endphp
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2 {{ $code == $currentLocale ? 'active' : '' }}"
                 href="{{ $switchUrl }}">
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

  /* Keep page content below the fixed navbar */
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
    margin-right: 0.5rem;
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

  .navbar-lang-flag {
    font-size: 1.2rem;
  }

  .navbar-nav .nav-link {
    transition: color 0.3s ease, background 0.3s ease;
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

  .dropdown-item:active {
    background-color: #3aaeb2;
  }

  .dropdown-item:hover {
    background-color: rgba(78, 205, 203, 0.1);
    color: #4ECDCB;
  }

  @media (max-width: 991.98px) {
    :root {
      --public-navbar-height: 76px;
    }
  }
</style>
