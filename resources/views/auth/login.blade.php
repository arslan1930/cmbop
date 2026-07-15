@extends('layouts.app')

@section('title', 'Login - Seolinkbuildings')

@section('content')
<style>
    .login-banner-wrapper {
        background: #eaf6f7;
        padding: 1.25rem;
        height: 100%;
    }
    .login-banner {
        background: linear-gradient(165deg, #3aaeb2 0%, #2c8a8d 100%);
        border-radius: 16px;
        padding: 1.85rem 1.65rem;
        color: #fff;
        height: 100%;
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 24px rgba(58, 174, 178, 0.18);
    }
    .login-banner .brand-bar {
        background: #fff;
        border-radius: 12px;
        padding: 0.9rem;
        text-align: center;
        margin-bottom: 1.4rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .login-banner .brand-bar img {
        max-height: 44px;
        width: auto;
    }
    .login-banner h3.banner-title {
        font-weight: 700;
        text-align: center;
        margin-bottom: 1.35rem;
        font-family: Georgia, 'Times New Roman', serif;
        font-size: 1.45rem;
        line-height: 1.3;
        color: #ffffff;
        letter-spacing: 0.2px;
    }
    .login-banner .welcome {
        margin-bottom: 1.5rem;
    }
    .login-banner .welcome p {
        margin-bottom: 0.4rem;
        font-size: 0.92rem;
        color: #e6f4f5;
        line-height: 1.5;
    }
    .login-banner .welcome p strong {
        color: #ffffff;
    }
    .stat-card {
        background: #ffffff;
        color: #2c8a8d;
        border-radius: 11px;
        padding: 0.8rem 0.85rem;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        height: 100%;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }
    .stat-card .stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #e6f4f5;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        color: #3aaeb2;
        flex-shrink: 0;
    }
    .stat-card .stat-text {
        text-align: center;
        flex: 1;
        line-height: 1.2;
        font-family: Georgia, serif;
    }
    .stat-card .stat-text .num {
        font-weight: 700;
        font-size: 1rem;
        color: #2c8a8d;
    }
    .stat-card .stat-text .label {
        font-size: 0.8rem;
        color: #5a8e90;
        font-weight: 500;
    }
    .trust-pill {
        background: #ffffff;
        color: #2c8a8d;
        border-radius: 30px;
        padding: 0.5rem 1.3rem;
        display: inline-block;
        font-weight: 700;
        font-family: Georgia, serif;
        font-size: 0.95rem;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .trust-list {
        list-style: disc;
        padding-left: 1.15rem;
        margin-bottom: 0;
    }
    .trust-list li {
        margin-bottom: 0.5rem;
        font-size: 0.84rem;
        color: #e6f4f5;
        line-height: 1.45;
    }
    .testimonial {
        font-size: 0.76rem;
        text-align: center;
        opacity: 0.95;
        line-height: 1.5;
        color: #e6f4f5;
        font-style: italic;
    }
    .flags {
        text-align: center;
        margin-top: 0.5rem;
        font-size: 1.15rem;
    }
    .flags small {
        font-size: 0.75rem;
        vertical-align: middle;
        opacity: 0.9;
        color: #e6f4f5;
        font-style: normal;
    }
    .counter-num {
        display: inline-block;
    }
</style>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 py-5">
            <div class="card shadow rounded-3 overflow-hidden">
                <div class="row g-0">

                    {{-- Left Banner (HTML) --}}
                    <div class="col-md-7 d-none d-md-block">
                        <div class="login-banner-wrapper">
                            <div class="login-banner">

                                {{-- Brand Logo --}}
                                <div class="brand-bar">
                                    <img src="{{ asset('assets/img/logo1.png') }}" alt="SEO Buildings">
                                </div>

                                {{-- Title --}}
                                <h3 class="banner-title">Europe's Trusted Backlink Marketplace</h3>

                                {{-- Welcome --}}
                                <div class="welcome">
                                    <p><strong>Welcome Back!</strong> 👋</p>
                                    <p>Access your dashboard to manage orders, track links, and grow your rankings.</p>
                                </div>

                                {{-- Stats Grid --}}
                                <div class="row g-3 mb-3">
                                    <div class="col-6">
                                        <div class="stat-card">
                                            <div class="stat-icon"><i class="fa-solid fa-globe"></i></div>
                                            <div class="stat-text">
                                                <div class="num"><span class="counter-num" data-target="150" data-suffix="+">0+</span></div>
                                                <div class="label">Publishers</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-card">
                                            <div class="stat-icon"><i class="fa-solid fa-link"></i></div>
                                            <div class="stat-text">
                                                <div class="num"><span class="counter-num" data-target="1000" data-suffix="+">0+</span></div>
                                                <div class="label">Links Delivered</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-card">
                                            <div class="stat-icon"><i class="fa-solid fa-earth-europe"></i></div>
                                            <div class="stat-text">
                                                <div class="num"><span class="counter-num" data-target="15" data-suffix="+">0+</span></div>
                                                <div class="label">EU Countries</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-card">
                                            <div class="stat-icon"><i class="fa-solid fa-star"></i></div>
                                            <div class="stat-text">
                                                <div class="num"><span class="counter-num" data-target="4.8" data-suffix="/5" data-decimal="1">0/5</span></div>
                                                <div class="label">Clients Rating</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Trust pill --}}
                                <div class="text-center mb-3">
                                    <span class="trust-pill">Why <span class="counter-num" data-target="500" data-suffix="+">0+</span> Clients Trust Us:</span>
                                </div>

                                {{-- Trust list --}}
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <ul class="trust-list">
                                            <li>Real European Publishers — No PBNs</li>
                                            <li>Fast Delivery — 24-72 Hours</li>
                                            <li>100% Money-Back Guarantee</li>
                                        </ul>
                                    </div>
                                    <div class="col-6">
                                        <ul class="trust-list">
                                            <li>Transparent Pricing — No Hidden Fees</li>
                                            <li>Native Content in 6+ Languages</li>
                                            <li>Dedicated Support Team</li>
                                        </ul>
                                    </div>
                                </div>

                                {{-- Testimonial --}}
                                <div class="mt-auto">
                                    <div class="testimonial">
                                        "Best link-building platform for European backlinks. Fast, reliable, quality sites."<br>
                                        — Marcus T., SEO Agency Owner, Germany
                                    </div>
                                    <div class="flags">
                                        🇩🇪 🇪🇸 🇵🇱 🇳🇱 🇵🇹 🇮🇹 🇧🇪 🇨🇭 <small>more ...</small>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    {{-- Right Form --}}
                    <div class="col-md-5 p-4 p-md-5">
                        <h2 class="text-center mb-4">Login</h2>

                        <form id="loginForm">
                            @csrf

                            {{-- Email --}}
                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control" required>
                                <div class="invalid-feedback" id="emailError"></div>
                            </div>

                            {{-- Password --}}
                            <div class="mb-3">
                                <label>Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="password" class="form-control" required>
                                    <span class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </span>
                                </div>
                                <div class="invalid-feedback" id="passwordError"></div>
                            </div>

                            {{-- Forgot Password --}}
                            <div class="mb-3 text-end">
                                <a href="{{ route('password.request') }}" class="text-decoration-underline">Forgot Password?</a>
                            </div>

                            {{-- reCAPTCHA --}}
                            <!-- <div class="mb-3">
                                <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                            </div> -->

                            {{-- Submit --}}
                            <button class="btn btn-primary w-100">Login</button>

                            {{-- Divider --}}
                            <div class="position-relative my-4">
                                <hr>
                                <div class="position-absolute top-50 start-50 translate-middle bg-white px-3" style="margin-top: -0.5px;">
                                    <span class="text-muted">or</span>
                                </div>
                            </div>

                            {{-- Google Login Button --}}
                            <a href="{{ route('auth.google') }}" class="btn btn-outline-danger w-100 d-flex align-items-center justify-content-center gap-2">
                                <svg width="20" height="20" viewBox="0 0 24 24">
                                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                </svg>
                                Continue with Google
                            </a>

                            {{-- Resend Verification --}}
                            <div class="text-center mt-3" id="resendDiv" style="display:none;">
                                <button type="button" class="btn btn-link p-0" id="resendBtn">Resend Verification Email</button>
                            </div>

                            {{-- Register --}}
                            <div class="text-center mt-3">
                                Don’t have an account?
                                <a href="{{ route('register') }}" class="text-decoration-underline">Register Here</a>
                            </div>

                            {{-- Back Home --}}
                            <div class="mt-4">
                                <a href="{{ url('/') }}" class="text-decoration-underline">← Back to Home</a>
                            </div>

                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toast --}}
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<script>
// ===== Counter Animation =====
function animateCounter(el) {
    const target = parseFloat(el.dataset.target);
    const suffix = el.dataset.suffix || '';
    const decimals = parseInt(el.dataset.decimal || 0);
    const duration = 1800;
    const startTime = performance.now();

    function update(now) {
        const elapsed = now - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const value = target * eased;
        el.textContent = value.toFixed(decimals) + suffix;
        if (progress < 1) {
            requestAnimationFrame(update);
        } else {
            el.textContent = target.toFixed(decimals) + suffix;
        }
    }
    requestAnimationFrame(update);
}

document.addEventListener('DOMContentLoaded', () => {
    const counters = document.querySelectorAll('.counter-num');

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        counters.forEach(c => observer.observe(c));
    } else {
        counters.forEach(c => animateCounter(c));
    }
});

// Toggle password
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

// Submit
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