<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top" style="transition: all 0.3s ease; padding:1rem 0;">
  <div class="container">

    <!-- Logo -->
    <a class="navbar-brand fw-bold d-flex align-items-center" href="/">
      <img src="{{ asset('assets/img/logo1.png') }}" alt="Seolinkbuildings Logo" style="height:42px; margin-right:0.5rem; transition: all 0.3s ease;">
    </a>

    <!-- Toggle Button for Mobile -->
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" 
            aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Navbar Links -->
    <div class="collapse navbar-collapse" id="navbarContent">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="/link-building">Link Building</a></li>
        <li class="nav-item"><a class="nav-link" href="/digital-pr-seo">Digital PR & SEO</a></li>
        <li class="nav-item"><a class="nav-link" href="/fix-design-site">Fix & Design Site</a></li>
        <li class="nav-item"><a class="nav-link" href="/content-writing">Content Writing</a></li>

        @auth
          <!-- Dashboard Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2 text-white" href="{{ auth()->user()->getDashboardRoute() }}" 
               style="background-color:#4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              Dashboard
            </a>
          </li>

          <!-- Logout Button -->
          <li class="nav-item">
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="nav-link px-3 mx-lg-2" style="border:1px solid #4ECDCB; border-radius:0.5rem; font-weight:500; color:#4ECDCB; background:none; cursor:pointer;">
                Logout
              </button>
            </form>
          </li>
        @else
          <!-- Login Button -->
          <li class="nav-item">
            <a class="nav-link px-3 mx-lg-2" href="/login" 
               style="color:#4ECDCB; border:1px solid #4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              Login
            </a>
          </li>

          <!-- Sign Up Button -->
          <li class="nav-item">
            <a class="nav-link text-white px-3" href="/register" 
               style="background-color:#4ECDCB; border-radius:0.5rem; font-weight:500; transition: all 0.3s;">
              Sign Up
            </a>
          </li>
        @endauth

      </ul>
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
  .navbar-nav .nav-link[href="/login"]:hover {
    color: #2a9a95 !important;
    border-color: #2a9a95 !important;
    background-color: transparent !important;
  }

  /* Sign Up button hover */
  .navbar-nav .nav-link[href="/register"]:hover {
    background-color: #3aaeb2 !important; /* slightly darker than #4ECDCB */
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
</style>