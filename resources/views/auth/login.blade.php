@extends('layouts.app')

@section('title', 'Sign In - SEOLinkBuildings')

@section('content')
<link href="{{ asset('css/auth-pages.css') }}?v={{ @filemtime(public_path('css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

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
                                    <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings">
                                </div>

                                <div class="auth-panel-kicker">Your SEO workspace</div>
                                <h1 class="auth-panel-title">Welcome back</h1>
                                <p class="auth-panel-copy">
                                    Access your dashboard to manage orders, track campaigns, collaborate with publishers, and grow your online presence.
                                </p>

                                <ul class="auth-proof-list" aria-label="Why advertisers trust SEOLinkBuildings">
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        <div>
                                            <strong>Verified publishers</strong>
                                            <span>EU &amp; major NA network — no PBNs</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.2" fill="#0b6266" stroke="none"/></svg>
                                        </span>
                                        <div>
                                            <strong>Secure payments</strong>
                                            <span>Wallet checkout with clear placement pricing</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L18 7"/></svg>
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

                            <div class="auth-mobile-strip d-md-none" aria-label="Why advertisers trust us">
                                <strong>Welcome back to your SEO workspace</strong>
                                <ul>
                                    <li><span class="mi" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span> Verified publishers</li>
                                    <li><span class="mi" aria-hidden="true"><i class="fa-solid fa-lock"></i></span> Secure payments</li>
                                    <li><span class="mi" aria-hidden="true"><i class="fa-solid fa-rotate-left"></i></span> Money-back protection</li>
                                </ul>
                            </div>

                            <form id="loginForm" novalidate>
                                @csrf

                                <div class="mb-3">
                                    <label class="auth-label" for="loginEmail">Email</label>
                                    <input type="email" name="email" id="loginEmail" class="form-control auth-input" placeholder="you@company.com" autocomplete="email" required>
                                    <div class="invalid-feedback" id="emailError"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="auth-label" for="password">Password</label>
                                    <div class="input-group">
                                        <input type="password" name="password" id="password" class="form-control auth-input" placeholder="Enter your password" autocomplete="current-password" required>
                                        <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)" aria-label="Show or hide password">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback" id="passwordError"></div>
                                </div>

                                <div class="mb-3 text-end">
                                    <a href="{{ route('password.request') }}" class="auth-meta-link">Forgot password?</a>
                                </div>

                                <button type="submit" class="auth-cta">Access Dashboard</button>

                                <div class="auth-divider"><span>or</span></div>

                                <a href="{{ route('auth.google') }}" class="auth-google">
                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                    </svg>
                                    Continue with Google
                                </a>

                                <div class="text-center mt-3" id="resendDiv" style="display:none;">
                                    <button type="button" class="btn btn-link p-0 auth-meta-link" id="resendBtn">Resend Verification Email</button>
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
                                    <a href="{{ route('register') }}" class="auth-meta-link">Create Account</a>
                                    <div class="mt-2">
                                        <a href="{{ url('/') }}" class="auth-meta-link">← Back to Home</a>
                                    </div>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toast --}}
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<script>
function togglePassword(id, el){
    const input = document.getElementById(id);
    const icon = el.querySelector('i');

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

    document.querySelectorAll('.form-control').forEach(i=>i.classList.remove('is-invalid'));

    const formData = new FormData(this);

    const res = await fetch("{{ route('login.post') }}", {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: formData
    });

    let data;
    try {
        data = await res.json();
    } catch (e) {
        alert("Server error occurred.");
        return;
    }

    const toastContainer = document.getElementById('toastContainer');
    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-white border-0';

    if(data.status === 'success'){
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${data.message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-success');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

        setTimeout(() => {
            window.location.href = data.redirect;
        }, 1500);

    } else if(data.status === 'validation'){
        const firstError = Object.values(data.errors)[0][0];
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${firstError}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

    } else if(data.status === 'unverified'){
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${data.message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-warning');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();

        const resendDiv = document.getElementById('resendDiv');
        resendDiv.style.display = 'block';
        const resendBtn = document.getElementById('resendBtn');

        resendBtn.onclick = async function(){
            if(!data.email) return;

            const sendingToast = document.createElement('div');
            sendingToast.className = 'toast align-items-center text-white border-0 bg-info';
            sendingToast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">Sending verification email...</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            toastContainer.appendChild(sendingToast);
            const sendingToastInstance = new bootstrap.Toast(sendingToast);
            sendingToastInstance.show();

            try {
                const emailData = new FormData();
                emailData.append('email', data.email);

                const res2 = await fetch("{{ route('verification.resend') }}", {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: emailData
                });

                const result = await res2.json();

                sendingToastInstance.hide();

                const toast2 = document.createElement('div');
                toast2.className = 'toast align-items-center text-white border-0';
                toast2.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">${result.message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                `;
                toast2.classList.add(result.status === 'success' ? 'bg-success' : 'bg-danger');
                toastContainer.appendChild(toast2);
                new bootstrap.Toast(toast2).show();

            } catch (err) {
                sendingToastInstance.hide();
                const toast2 = document.createElement('div');
                toast2.className = 'toast align-items-center text-white border-0 bg-danger';
                toast2.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">Failed to send email. Please try again.</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                `;
                toastContainer.appendChild(toast2);
                new bootstrap.Toast(toast2).show();
            }
        };

    } else {
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${data.message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        toastEl.classList.add('bg-danger');
        toastContainer.appendChild(toastEl);
        new bootstrap.Toast(toastEl).show();
    }
});
</script>

@endsection
