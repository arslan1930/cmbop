<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong — SEOLinkBuildings</title>
    <meta name="robots" content="noindex">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/brand-colors.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/button-system.css') }}" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; background: #f8fafc; font-family: Poppins, system-ui, sans-serif; }
        .error-card { max-width: 520px; margin: auto; text-align: center; padding: 2rem; }
        .error-code { font-size: 4rem; font-weight: 700; color: var(--brand-primary, #185054); line-height: 1; }
        .error-icon {
            width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            background: var(--brand-warning-bg, #fef3c7); color: var(--brand-warning, #d97706);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon" aria-hidden="true"><i class="fa-solid fa-screwdriver-wrench"></i></div>
        <div class="error-code">500</div>
        <h1 class="h4 mt-3 mb-2">Something went wrong</h1>
        <p class="text-muted mb-4">We’re on it. Please try again in a moment — your data is safe.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
            <a href="{{ url('/') }}" class="btn btn-primary">Back to home</a>
            <button type="button" class="btn btn-outline-secondary" onclick="window.location.reload()">Try again</button>
            <a href="mailto:support@seolinkbuildings.com" class="btn btn-outline-primary">Email support</a>
        </div>
        <p class="small text-muted mb-0">If this keeps happening, tell us what you were doing via Help &amp; feedback.</p>
    </div>
</body>
</html>
