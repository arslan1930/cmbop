@extends('layouts.app')

@section('title', 'Two-factor authentication')
@section('description', 'Enter the code from your authenticator app to finish signing in.')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="auth-page">
    <div class="container auth-shell">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="auth-card p-4 p-md-5">
                    <h1 class="auth-form-title h3">Two-factor authentication</h1>
                    <p class="auth-form-sub">Enter the 6-digit code from your authenticator app, or a backup code.</p>

                    <form method="POST" action="{{ route('login.two-factor.store') }}" novalidate>
                        @csrf
                        <div class="mb-3">
                            <label class="auth-label" for="twoFactorCode">Authentication code</label>
                            <input type="text" name="code" id="twoFactorCode" class="form-control auth-input"
                                   inputmode="numeric" autocomplete="one-time-code" required maxlength="32"
                                   placeholder="123456" autofocus
                                   aria-describedby="twoFactorCodeHint">
                            <div id="twoFactorCodeHint" class="form-text">6 digits from the app, or a backup code.</div>
                            @error('code')
                                <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="auth-cta">Continue</button>
                    </form>

                    <div class="auth-foot-links mt-3">
                        <a href="{{ route('login', absolute: false) }}" class="auth-meta-link">← Back to sign in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
