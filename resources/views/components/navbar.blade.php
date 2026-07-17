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
  
  if (!empty($segments) && in_array($segments[0], $availableLocales)) {
    $currentLocale = $segments[0];
  }
  
  $currentLanguage = $languages[$currentLocale] ?? $languages['en'];
  
  // Build path without locale for URL switching
  $pathWithoutLocale = '';
  if (!empty($segments)) {
    if (in_array($segments[0], $availableLocales)) {
      $pathWithoutLocale = implode('/', array_slice($segments, 1));
    } else {
      $pathWithoutLocale = implode('/', $segments);
    }
  }
@endphp

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top" style="transition: all 0.3s ease; padding:1rem 0;">
  <div class="container">

    <!-- Logo -->
    <a class="navbar-brand fw-bold d-flex align-items-center" href="{{ $currentLocale == 'en' ? '/' : '/' . $currentLocale }}">
      <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings" style="height:42px; margin-right:0.5rem; transition: all 0.3s ease;">
    </a>

    <!-- Toggle Button for Mobile -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" 
            aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Navbar Links -->
    <div class="collapse navbar-collapse" id="navbarContent">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
          <!-- <li class="nav-item"><a class="nav-link" href="/link-building">Link Building</a></li>
          <li class="nav-item"><a class="nav-link" href="/digital-pr-seo">Digital PR & SEO</a></li>
          <li class="nav-item"><a class="nav-link" href="/fix-design-site">Fix & Design Site</a></li>
          <li class="nav-item"><a class="nav-link" href="/content-writing">Content Writing</a></li> -->

        @auth
          <!-- Dashboard Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 text-white" href="{{ auth()->user()->getDashboardRoute() }}" 
               style="background-color:#4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              {{ __('messages.Dashboard') }}
            </a>
          </li>

          <!-- Logout Button -->
          <li class="nav-item">
            <form method="POST" action="{{ $currentLocale == 'en' ? route('logout') : '/' . $currentLocale . '/logout' }}">
              @csrf
              <button type="submit" class="nav-link px-3 mx-lg-2" style="border:1px solid #4ECDCB; border-radius:0.5rem; font-weight:500; color:#4ECDCB; background:none; cursor:pointer;">
                Logout
              </button>
            </form>
          </li>
        @else
          <!-- Login Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2" href="{{ $currentLocale == 'en' ? '/login' : '/' . $currentLocale . '/login' }}" 
               style="color:#4ECDCB; border:1px solid #4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              {{ __('messages.login') }}
            </a>
          </li>

          <!-- Sign Up Button -->
          <li class="nav-item">
            <a class="nav-link text-white px-3" href="{{ $currentLocale == 'en' ? '/register' : '/' . $currentLocale . '/register' }}" 
               style="background-color:#4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              {{ __('messages.Sign Up') }}
            </a>
          </li>
        @endauth

      </ul>

      <!-- Blog Button like login -->
        <i class="fa fa-blog me-2"></i> Blog@php
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
  
  if (!empty($segments) && in_array($segments[0], $availableLocales)) {
    $currentLocale = $segments[0];
  }
  
  $currentLanguage = $languages[$currentLocale] ?? $languages['en'];
  
  // Build path without locale for URL switching
  $pathWithoutLocale = '';
  if (!empty($segments)) {
    if (in_array($segments[0], $availableLocales)) {
      $pathWithoutLocale = implode('/', array_slice($segments, 1));
    } else {
      $pathWithoutLocale = implode('/', $segments);
    }
  }
@endphp

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top" style="transition: all 0.3s ease; padding:1rem 0;">
  <div class="container">

    <!-- Logo -->
    <a class="navbar-brand fw-bold d-flex align-items-center" href="{{ $currentLocale == 'en' ? '/' : '/' . $currentLocale }}">
      <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings" style="height:42px; margin-right:0.5rem; transition: all 0.3s ease;">
    </a>

    <!-- Toggle Button for Mobile -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" 
            aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Navbar Links -->
    <div class="collapse navbar-collapse" id="navbarContent">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
          <!-- <li class="nav-item"><a class="nav-link" href="/link-building">Link Building</a></li>
          <li class="nav-item"><a class="nav-link" href="/digital-pr-seo">Digital PR & SEO</a></li>
          <li class="nav-item"><a class="nav-link" href="/fix-design-site">Fix & Design Site</a></li>
          <li class="nav-item"><a class="nav-link" href="/content-writing">Content Writing</a></li> -->

        @auth
          <!-- Dashboard Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 text-white" href="{{ auth()->user()->getDashboardRoute() }}" 
               style="background-color:#4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              {{ __('messages.Dashboard') }}
            </a>
          </li>

          <!-- Logout Button -->
          <li class="nav-item">
            <form method="POST" action="{{ $currentLocale == 'en' ? route('logout') : '/' . $currentLocale . '/logout' }}">
              @csrf
              <button type="submit" class="nav-link px-3 mx-lg-2" style="border:1px solid #4ECDCB; border-radius:0.5rem; font-weight:500; color:#4ECDCB; background:none; cursor:pointer;">
                {{ __('messages.logout') }}
              </button>
            </form>
          </li>
        @else
          <!-- Login Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2" href="{{ $currentLocale == 'en' ? '/login' : '/' . $currentLocale . '/login' }}" 
               style="color:#4ECDCB; border:1px solid #4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              {{ __('messages.login') }}
            </a>
          </li>

          <!-- Sign Up Button -->
          <li class="nav-item">
            <a class="nav-link text-white px-3" href="{{ $currentLocale == 'en' ? '/register' : '/' . $currentLocale . '/register' }}" 
               style="background-color:#4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              {{ __('messages.Sign Up') }}
            </a>
          </li>
        @endauth

      </ul>

      <!-- Blog Button like login -->
      <!-- <a href="{{ $currentLocale == 'en' ? '/blog' : '/' . $currentLocale . '/blog' }}" class="btn btn-outline-secondary ms-lg-3">
        <i class="fa fa-blog me-2"></i> Blog
      </a> -->

      <!-- Language Switcher Dropdown -->
      <div class="dropdown ms-lg-2 d-inline-block">
        <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" 
                type="button" 
                id="languageDropdown" 
                data-bs-toggle="dropdown" 
                aria-expanded="false"
                style="border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.375rem 0.75rem;">
          <span style="font-size: 1.2rem;">{!! $currentLanguage['flag'] !!}</span>
          <span>{{ $currentLanguage['name'] }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown" style="min-width: 150px;">
          @foreach($languages as $code => $language)
            @php
              // Build the correct URL for each language
              if ($code == 'en') {
                $switchUrl = $pathWithoutLocale ? '/' . $pathWithoutLocale : '/';
              } else {
                $switchUrl = $pathWithoutLocale ? '/' . $code . '/' . $pathWithoutLocale : '/' . $code;
              }
              // Clean up double slashes
              $switchUrl = str_replace('//', '/', $switchUrl);
            @endphp
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2 {{ $code == $currentLocale ? 'active' : '' }}" 
                 href="{{ $switchUrl }}">
                <span style="font-size: 1.2rem;">{!! $language['flag'] !!}</span>
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
  // Navbar shrink on scroll
  window.addEventListener('scroll', function() {
    const navbar = document.getElementById('mainNavbar');
    const logo = navbar.querySelector('img');
    if (window.scrollY > 50) {
      navbar.style.padding = '0.6rem 0';
      navbar.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.15)';
      logo.style.height = '36px';
    } else {
      navbar.style.padding = '1rem 0';
      navbar.style.boxShadow = '0 0.25rem 0.5rem rgba(0,0,0,0.1)';
      logo.style.height = '42px';
    }
  });
</script>

<style>
  /* Navbar link hover */
  .navbar-nav .nav-link {
    transition: color 0.3s ease, background 0.3s ease;
  }
  .navbar-nav .nav-link:hover {
    color: #4ECDCB !important;
    background-color: transparent !important;
  }

  /* Login button hover */
  .navbar-nav .nav-link[href*="/login"]:hover {
    color: #2a9a95 !important;
    border-color: #2a9a95 !important;
    background-color: transparent !important;
  }

  /* Sign Up button hover */
  .navbar-nav .nav-link[href*="/register"]:hover {
    background-color: #3aaeb2 !important;
    color: #fff !important;
  }

  /* Dashboard hover */
  .navbar-nav .nav-link[href*="dashboard"]:hover {
    background-color: #3aaeb2 !important;
    color: #fff !important;
  }

  /* Logout hover */
  .navbar-nav form button.nav-link:hover {
    color: #2a9a95 !important;
    border-color: #2a9a95 !important;
    background-color: transparent !important;
  }

  /* Language dropdown styles */
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

  /* Blog button hover */
  .btn-outline-secondary:hover {
    background-color: #4ECDCB !important;
    border-color: #4ECDCB !important;
    color: white !important;
  }
</style>
      </a>

      <!-- Language Switcher Dropdown -->
      <div class="dropdown ms-lg-2 d-inline-block">
        <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" 
                type="button" 
                id="languageDropdown" 
                data-bs-toggle="dropdown" 
                aria-expanded="false"
                style="border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.375rem 0.75rem;">
          <span style="font-size: 1.2rem;">{!! $currentLanguage['flag'] !!}</span>
          <span>{{ $currentLanguage['name'] }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown" style="min-width: 150px;">
          @foreach($languages as $code => $language)
            @php
              // Build the correct URL for each language
              if ($code == 'en') {
                $switchUrl = $pathWithoutLocale ? '/' . $pathWithoutLocale : '/';
              } else {
                $switchUrl = $pathWithoutLocale ? '/' . $code . '/' . $pathWithoutLocale : '/' . $code;
              }
              // Clean up double slashes
              $switchUrl = str_replace('//', '/', $switchUrl);
            @endphp
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2 {{ $code == $currentLocale ? 'active' : '' }}" 
                 href="{{ $switchUrl }}">
                <span style="font-size: 1.2rem;">{!! $language['flag'] !!}</span>
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
  // Navbar shrink on scroll
  window.addEventListener('scroll', function() {
    const navbar = document.getElementById('mainNavbar');
    const logo = navbar.querySelector('img');
    if (window.scrollY > 50) {
      navbar.style.padding = '0.6rem 0';
      navbar.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.15)';
      logo.style.height = '36px';
    } else {
      navbar.style.padding = '1rem 0';
      navbar.style.boxShadow = '0 0.25rem 0.5rem rgba(0,0,0,0.1)';
      logo.style.height = '42px';
    }
  });
</script>

<style>
  /* Navbar link hover */
  .navbar-nav .nav-link {
    transition: color 0.3s ease, background 0.3s ease;
  }
  .navbar-nav .nav-link:hover {
    color: #4ECDCB !important;
    background-color: transparent !important;
  }

  /* Login button hover */
  .navbar-nav .nav-link[href*="/login"]:hover {
    color: #2a9a95 !important;
    border-color: #2a9a95 !important;
    background-color: transparent !important;
  }

  /* Sign Up button hover */
  .navbar-nav .nav-link[href*="/register"]:hover {
    background-color: #3aaeb2 !important;
    color: #fff !important;
  }

  /* Dashboard hover */
  .navbar-nav .nav-link[href*="dashboard"]:hover {
    background-color: #3aaeb2 !important;
    color: #fff !important;
  }

  /* Logout hover */
  .navbar-nav form button.nav-link:hover {
    color: #2a9a95 !important;
    border-color: #2a9a95 !important;
    background-color: transparent !important;
  }

  /* Language dropdown styles */
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

  /* Blog button hover */
  .btn-outline-secondary:hover {
    background-color: #4ECDCB !important;
    border-color: #4ECDCB !important;
    color: white !important;
  }
</style>