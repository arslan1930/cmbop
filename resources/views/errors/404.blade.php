<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found — SEOLinkBuildings</title>
    <meta name="robots" content="noindex">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="{{ asset('css/brand-colors.css') }}" rel="stylesheet">
    <link href="{{ asset('css/button-system.css') }}" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; background: #f8fafc; font-family: Poppins, system-ui, sans-serif; }
        .error-card { max-width: 520px; margin: auto; text-align: center; padding: 2rem; }
        .error-code { font-size: 4rem; font-weight: 700; color: var(--brand-primary, #0b6266); line-height: 1; }
        .error-icon {
            width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            background: var(--brand-primary-bg, #e8f8f7); color: var(--brand-primary, #0b6266);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon" aria-hidden="true"><i class="fa-solid fa-compass"></i></div>
        <div class="error-code">404</div>
        <h1 class="h4 mt-3 mb-2">Page not found</h1>
        <p class="text-muted mb-4">That link doesn’t exist or may have moved. Try one of these instead.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
            <a href="{{ url('/') }}" class="btn btn-primary">Back to home</a>
            <a href="{{ url('/contact') }}" class="btn btn-outline-secondary">Contact support</a>
            @auth
                @if(optional(auth()->user())->activeRole() === 'advertiser')
                    <a href="{{ url('/advertiser/catalog') }}" class="btn btn-outline-primary">Browse catalog</a>
                @elseif(optional(auth()->user())->activeRole() === 'publisher')
                    <a href="{{ url('/publisher/websites') }}" class="btn btn-outline-primary">My websites</a>
                @endif
            @else
                <a href="{{ url('/login') }}" class="btn btn-outline-primary">Log in</a>
            @endauth
        </div>
        <p class="small text-muted mb-0">Need help? Use the Help &amp; feedback button on any page.</p>
    </div>
</body>
</html>
