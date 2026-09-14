@extends('layouts.app')

@section('title', __('messages.meta_login_title'))
@section('description', __('messages.meta_login_description'))

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="auth-page">
    <div class="container auth-shell">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="auth-card">
                    <div class="row g-0">

                        {{-- Brand panel --}}
                        <div class="col-md-6 d-none d-md-block">
                            <div class="auth-panel h-100">
                                <div class="auth-brand">
                                    <img src="{{ asset('assets/img/logo.svg') }}?v={{ @filemtime(public_path('assets/img/logo.svg')) ?: '1' }}"
                                         alt="SEOLinkBuildings"
                                         width="220"
                                         height="48">
                                </div>

                                <div class="auth-panel-kicker">Your SEO workspace</div>
                                <h1 class="auth-panel-title">Welcome back</h1>
                                <p class="auth-panel-copy">
                                    Access your dashboard to manage orders, track campaigns, collaborate with publishers, and grow your online presence.
                                </p>

                                <ul class="auth-proof-list" aria-label="Why advertisers trust SEOLinkBuildings">
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        <div>
                                            <strong>Verified publishers</strong>
                                            <span>EU &amp; major NA network — no PBNs</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.2" fill="#1a585e" stroke="none"/></svg>
                                        </span>
                                        <div>
                                            <strong>Secure payments</strong>
                                            <span>Wallet checkout with clear placement pricing</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L18 7"/></svg>
                                        </span>
                                        <div>
                                            <strong>Real-time tracking</strong>
                                            <span>Follow every order from purchase to live URL</span>
                                        </div>
                                    </li>
                                </ul>

                                <blockquote class="auth-quote">
                                    “Best link-building platform for European backlinks. Fast, reliable, quality sites.”
                                    <cite>— Marcus T., SEO Agency Owner, Germany</cite>
                                </blockquote>
                            </div>
                        </div>

                        {{-- Form --}}
                        <div class="col-md-6 auth-form-col">
                            <h2 class="auth-form-title">Sign in</h2>
                            <p class="auth-form-sub">Continue managing placements, campaigns, and publisher collaboration.</p>

                            @if(session('message'))
                                <div class="alert alert-success py-2 px-3 mb-3" role="status">{{ session('message') }}</div>
                            @endif
                            @if(session('status'))
                                <div class="alert alert-info py-2 px-3 mb-3" role="status">{{ session('status') }}</div>
                            @endif

                            <div class="auth-mobile-strip d-md-none" aria-label="Why advertisers trust us">
                                <strong>Welcome back to your SEO workspace</strong>
                                <ul>
                                    <li>
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        Verified publishers
                                    </li>
                                    <li>
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.2" fill="#1a585e" stroke="none"/></svg>
                                        </span>
                                        Secure payments
                                    </li>
                                    <li>
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L18 7"/></svg>
                                        </span>
                                        Real-time tracking
                                    </li>
                                </ul>
                            </div>

                            <form id="loginForm" novalidate>
                                @csrf

                                <div class="mb-3">
                                    <label class="auth-label" for="loginEmail">Email</label>
                                    <input type="email" name="email" id="loginEmail" class="form-control auth-input" placeholder="you@company.com" autocomplete="email" required
                                           aria-describedby="emailError">
                                    <div class="invalid-feedback" id="emailError" role="alert" aria-live="polite"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="auth-label" for="password">Password</label>
                                    <div class="input-group">
                                        <input type="password" name="password" id="password" class="form-control auth-input" placeholder="Enter your password" autocomplete="current-password" required
                                               aria-describedby="passwordError">
                                        <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)" aria-label="Show or hide password">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback" id="passwordError" role="alert" aria-live="polite"></div>
                                </div>

                                <div class="mb-3 d-flex justify-content-between align-items-center gap-2">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"
                                               title="Stay signed in on this device">
                                        <label class="form-check-label mb-0" for="remember">Remember me</label>
                                    </div>
                                    <a href="{{ route('password.request', absolute: false) }}" class="auth-meta-link">Forgot password?</a>
                                </div>

                                <button type="submit" class="auth-cta">Access Dashboard</button>

                                <div class="text-center mt-3" id="resendDiv">
                                    <button type="button" class="btn btn-link p-0 auth-meta-link" id="resendBtn">Need a verification email?</button>
                                </div>

                                <div class="auth-trust-row" aria-label="Trust indicators">
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Verified Publishers</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Secure Payments</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Transparent Pricing</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Real-Time Order Tracking</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Dedicated Support</span>
                                </div>

                                <div class="auth-foot-links">
                                    Don’t have an account?
                                    <a href="{{ route('register', absolute: false) }}" class="auth-meta-link">Create Account</a>
                                    <div class="mt-2">
                                        <a href="{{ url('/') }}" class="auth-meta-link">← Back to Home</a>
                                    </div>
                                </div>
                            </form>

                            <div class="auth-divider"><span>or</span></div>

                            <div class="auth-social-stack">
                                <a href="{{ route('auth.google', absolute: false) }}" class="auth-google">
                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                    </svg>
                                    Continue with Google
                                </a>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toast --}}
<div class="slb-toast-stack" id="toastContainer"></div>

<script>
function togglePassword(id, el){
    const input = document.getElementById(id);
    const icon = el.querySelector('i');
    if(!input || !icon) return;

    if(input.type === 'password'){
        input.type = 'text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

document.getElementById('loginForm').addEventListener('submit', async function(e){
    e.preventDefault();

    document.querySelectorAll('.form-control').forEach(i=>{
        i.classList.remove('is-invalid');
        i.removeAttribute('aria-invalid');
    });
    document.querySelectorAll('#emailError, #passwordError').forEach(el => { el.textContent = ''; });

    const formData = new FormData(this);

    const res = await fetch("{{ route('login.post', absolute: false) }}", {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        },
        body: formData
    });

    let data;
    try {
        data = await res.json();
    } catch (e) {
        slbAlert({ icon: 'error', title: 'Server error', text: 'Please try again in a moment.' });
        return;
    }

    const toastContainer = document.getElementById('toastContainer');

    // Field-level errors: the invalid-feedback divs used to stay empty, so a
    // screen-reader user only got a toast with no link to the offending input.
    function showFieldErrors(errors) {
        const map = { email: ['loginEmail', 'emailError'], password: ['password', 'passwordError'] };
        Object.keys(errors || {}).forEach(function (field) {
            const pair = map[field];
            if (!pair) return;
            const input = document.getElementById(pair[0]);
            const feedback = document.getElementById(pair[1]);
            const message = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
            if (input) {
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
            }
            if (feedback) feedback.textContent = message;
        });
    }

    function buildAuthToast(message, variant) {
        const solid = variant === 'success' || variant === 'danger';
        const toastEl = document.createElement('div');
        toastEl.setAttribute('role', variant === 'success' ? 'status' : 'alert');
        toastEl.setAttribute('aria-live', variant === 'success' ? 'polite' : 'assertive');
        toastEl.className = 'toast align-items-center border-0 '
            + (solid ? 'text-white ' : 'text-dark ')
            + 'bg-' + variant;
        const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close';
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="${closeClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;
        return toastEl;
    }

    if(data.status === 'success' || data.status === 'two_factor'){
        const toastEl = buildAuthToast(data.message, 'success');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

        setTimeout(() => {
            window.location.href = data.redirect;
        }, data.status === 'two_factor' ? 400 : 1500);

    } else if(data.status === 'validation'){
        showFieldErrors(data.errors);
        const firstError = Object.values(data.errors)[0][0];
        const toastEl = buildAuthToast(firstError, 'danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

    } else {
        const toastEl = buildAuthToast(data.message, 'danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();
    }
});

document.getElementById('resendBtn')?.addEventListener('click', async function () {
    const email = (document.getElementById('loginEmail')?.value || '').trim();
    const toastContainer = document.getElementById('toastContainer');
    if (!email) {
        const toastEl = buildLoginToast('Enter your email first.', 'danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();
        return;
    }

    const sendingToast = buildLoginToast('Sending verification email...', 'info');
    toastContainer.appendChild(sendingToast);
    const sendingToastInstance = new bootstrap.Toast(sendingToast);
    sendingToastInstance.show();

    try {
        const emailData = new FormData();
        emailData.append('email', email);

        const res2 = await fetch("{{ route('verification.resend') }}", {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: emailData
        });

        const result = await res2.json();
        sendingToastInstance.hide();

        const toast2 = buildLoginToast(result.message, result.status === 'success' ? 'success' : 'danger');
        toastContainer.appendChild(toast2);
        new bootstrap.Toast(toast2).show();
    } catch (err) {
        sendingToastInstance.hide();
        const toast2 = buildLoginToast('Failed to send email. Please try again.', 'danger');
        toastContainer.appendChild(toast2);
        new bootstrap.Toast(toast2).show();
    }
});

function buildLoginToast(message, variant) {
    const solid = variant === 'success' || variant === 'danger';
    const toastEl = document.createElement('div');
    toastEl.setAttribute('role', variant === 'success' ? 'status' : 'alert');
    toastEl.setAttribute('aria-live', variant === 'success' ? 'polite' : 'assertive');
    toastEl.className = 'toast align-items-center border-0 '
        + (solid ? 'text-white ' : 'text-dark ')
        + 'bg-' + variant;
    const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close';
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="${closeClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    return toastEl;
}
</script>

@endsection
