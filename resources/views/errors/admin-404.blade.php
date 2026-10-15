@php
    $isAdmin = auth()->check() && optional(auth()->user())->isAdmin();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found — Admin — SEOLinkBuildings</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="{{ asset('assets/vendor/bootstrap-5.3.0/bootstrap.min.css') }}?v={{ @filemtime(public_path('assets/vendor/bootstrap-5.3.0/bootstrap.min.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/slb-icons.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/brand-colors.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/button-system.css') }}" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; background: #0f2f32; font-family: Poppins, system-ui, sans-serif; color: #f8fafc; }
        .error-card { max-width: 520px; margin: auto; text-align: center; padding: 2rem; }
        .error-code { font-size: 4rem; font-weight: 700; color: #7ed4d7; line-height: 1; }
        .error-icon {
            width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            background: rgba(126, 212, 215, .15); color: #7ed4d7;
        }
        .text-muted { color: #c5d4d5 !important; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon" aria-hidden="true"><i class="fa-solid fa-compass"></i></div>
        <div class="error-code">404</div>
        <h1 class="h4 mt-3 mb-2">Admin page not found</h1>
        <p class="text-muted mb-4">That admin URL does not exist or may have moved.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
            @if($isAdmin)
                <a href="{{ route('admin.dashboard') }}" class="btn btn-primary">Admin dashboard</a>
                <a href="{{ staff_route('sites.index') }}" class="btn btn-outline-light">Sites</a>
                <a href="{{ route('admin.users.index') }}" class="btn btn-outline-light">Users</a>
            @elseif(auth()->check())
                <a href="{{ url('/') }}" class="btn btn-primary">Back to home</a>
            @else
                <a href="{{ url('/login') }}" class="btn btn-primary">Log in</a>
            @endif
        </div>
    </div>
</body>
</html>
