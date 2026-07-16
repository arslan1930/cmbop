@extends('layouts.app')

@section('title', 'Create Account - SEOLinkBuildings')

@section('content')
<link href="{{ asset('css/auth-pages.css') }}?v={{ @filemtime(public_path('css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="auth-page">
    <div class="container auth-shell">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="auth-card">
                    <div class="row g-0">

                        {{-- Brand panel --}}
                        <div class="col-md-5 d-none d-md-block">
                            <div class="auth-panel h-100">
                                <div class="auth-brand">
                                    <img src="{{ asset('assets/img/logo1.png') }}" alt="SEOLinkBuildings">
                                </div>

                                <div class="auth-panel-kicker">Start free today</div>
                                <h1 class="auth-panel-title">Start building better backlinks</h1>
                                <p class="auth-panel-copy">
                                    Create your free account to discover verified publishers, manage guest post campaigns, track your spending, and grow your SEO with confidence.
                                </p>

                                <ul class="auth-proof-list" aria-label="What you get with SEOLinkBuildings">
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M12 22V12"/><path d="M2.5 9.5h19v2.5H2.5z"/><path d="M7.5 9.5C6 9.5 5 8.2 5 7.2S6.2 5 7.5 5c2 0 2.5 2 4.5 4.5C14 7 14.5 5 16.5 5 17.8 5 19 6.2 19 7.2s-1 2.3-2.5 2.3"/></svg>
                                        </span>
                                        <div>
                                            <strong>€20 welcome credit</strong>
                                            <span>Spend on your first orders — not withdrawable</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L18 7"/></svg>
                                        </span>
                                        <div>
                                            <strong>Free SEO audit</strong>
                                            <span>Backlink quality, authority, and next steps</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        <div>
                                            <strong>Verified publishers</strong>
                                            <span>EU &amp; major NA network — no PBNs</span>
                                        </div>
                                    </li>
                                </ul>

                                <blockquote class="auth-quote">
                                    Join advertisers who buy placements with clear pricing and tracked delivery.
                                    <cite>SEOLinkBuildings marketplace</cite>
                                </blockquote>
                            </div>
                        </div>

                        {{-- Form --}}
                        <div class="col-md-7 auth-form-col">
                            <h2 class="auth-form-title">Create your account</h2>
                            <p class="auth-form-sub">Build authority with verified publishers — free to start, no card required.</p>

                            <div class="auth-mobile-strip d-md-none" aria-label="Why join SEOLinkBuildings">
                                <strong>Start with €20 free credit</strong>
                                <ul>
                                    <li><span class="mi" aria-hidden="true"><i class="fa-solid fa-gift"></i></span> Welcome bonus for first orders</li>
                                    <li><span class="mi" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span> Free SEO audit on signup</li>
                                    <li><span class="mi" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span> Verified European publishers</li>
                                </ul>
                            </div>

                            <form id="registerForm" onsubmit="return false;" novalidate>
                                @csrf

                                <div class="mb-3">
                                    <label for="name" class="auth-label">Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" class="form-control auth-input" placeholder="Your full name" autocomplete="name" required>
                                    <div class="invalid-feedback" id="nameError"></div>
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="auth-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" id="email" class="form-control auth-input" placeholder="you@company.com" autocomplete="email" required>
                                    <div class="invalid-feedback" id="emailError"></div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label for="password" class="auth-label">Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" name="password" id="password" class="form-control auth-input pe-5" placeholder="Create a password" autocomplete="new-password" required>
                                            <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)" aria-label="Show or hide password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                            <div class="invalid-feedback" id="passwordError"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="password_confirmation" class="auth-label">Confirm password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control auth-input pe-5" placeholder="Repeat password" autocomplete="new-password" required>
                                            <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password_confirmation', this)" aria-label="Show or hide password confirmation"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                            <div class="invalid-feedback" id="password_confirmationError"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="auth-label">Register as <span class="text-danger">*</span></label>
                                    <div class="auth-role-grid" id="roleSelect" role="radiogroup" aria-label="Choose account type">
                                        <div class="auth-role-card role-card selected" data-value="advertiser" role="radio" aria-checked="true" tabindex="0">
                                            <i class="fa-solid fa-bullseye role-main" aria-hidden="true"></i>
                                            Advertiser
                                            <i class="fa-solid fa-check role-check" aria-hidden="true"></i>
                                        </div>
                                        <div class="auth-role-card role-card" data-value="publisher" role="radio" aria-checked="false" tabindex="0">
                                            <i class="fa-solid fa-file-lines role-main" aria-hidden="true"></i>
                                            Publisher
                                            <i class="fa-solid fa-check role-check" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                    <input type="hidden" name="role" id="roleInput" value="advertiser">
                                    <div class="invalid-feedback" id="roleError"></div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check mb-2">
                                        <input type="checkbox" class="form-check-input" name="terms" id="terms" required>
                                        <label class="form-check-label" for="terms">
                                            <span class="text-danger">*</span> I agree to the <a href="{{ route('terms-of-services') }}" target="_blank" rel="noopener" class="auth-meta-link">Terms of Service</a>.
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input type="checkbox" class="form-check-input" name="marketing" id="marketing">
                                        <label class="form-check-label" for="marketing">
                                            I consent to receiving marketing communications about services and offers.
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="newsletter" id="newsletter">
                                        <label class="form-check-label" for="newsletter">
                                            Send me the newsletter. See our <a href="{{ url('/privacy-policy') }}" target="_blank" class="auth-meta-link">Privacy Policy</a>.
                                        </label>
                                    </div>
                                </div>

                                <div class="d-flex flex-column flex-sm-row gap-2 mb-1">
                                    <button type="submit" class="auth-cta flex-fill" id="submitBtn">Create Account</button>
                                    <a href="{{ url('/login') }}" class="auth-secondary-btn flex-fill">Sign In</a>
                                </div>

                                <div class="auth-divider"><span>or</span></div>

                                <a href="{{ route('auth.google') }}" class="auth-google mb-2">
                                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                    </svg>
                                    Continue with Google
                                </a>

                                <div class="auth-trust-row" aria-label="Trust indicators">
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Verified Publishers</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Secure Payments</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Transparent Pricing</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Real-Time Order Tracking</span>
                                    <span><i class="fa-solid fa-check" aria-hidden="true"></i> Dedicated Support</span>
                                </div>

                                <div class="auth-foot-links">
                                    <a href="{{ url('/') }}" class="auth-meta-link">← Back to Home</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toast Container --}}
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<script>
function togglePassword(id, iconSpan){
    const input = document.getElementById(id);
    const icon = iconSpan.querySelector('i');
    if(input.type==='password'){
        input.type='text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        input.type='password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

function selectRoleCard(card){
    document.querySelectorAll('#roleSelect .role-card').forEach(c=>{
        c.classList.remove('selected');
        c.setAttribute('aria-checked', 'false');
    });
    card.classList.add('selected');
    card.setAttribute('aria-checked', 'true');
    document.getElementById('roleInput').value = card.dataset.value;
}

document.querySelectorAll('#roleSelect .role-card').forEach(card=>{
    card.addEventListener('click', function(){
        selectRoleCard(this);
    });
    card.addEventListener('keydown', function(e){
        if(e.key === 'Enter' || e.key === ' '){
            e.preventDefault();
            selectRoleCard(this);
        }
    });
});

document.getElementById('registerForm').addEventListener('submit', async function(e){
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    if(submitBtn.disabled) return;
    submitBtn.disabled = true;
    submitBtn.innerText = 'Creating account...';

    document.querySelectorAll('.form-control').forEach(input=>{
        input.classList.remove('is-invalid');
    });

    ['nameError','emailError','passwordError','password_confirmationError','roleError'].forEach(id=>{
        const el = document.getElementById(id);
        if(el) el.innerText='';
    });

    const toastContainer = document.getElementById('toastContainer');

    const role = document.getElementById('roleInput').value;
    if(!role){
        const toast = document.createElement('div');
        toast.className='toast align-items-center text-white border-0';
        toast.innerHTML=`<div class="d-flex"><div class="toast-body">Please select a role.</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        toast.classList.add('bg-warning');
        toastContainer.appendChild(toast);
        new bootstrap.Toast(toast,{delay:4000}).show();
        submitBtn.disabled=false;
        submitBtn.innerText='Create Account';
        return;
    }

    const sendingToast = document.createElement('div');
    sendingToast.className='toast align-items-center text-white border-0';
    sendingToast.innerHTML=`<div class="d-flex"><div class="toast-body">Sending verification email...</div></div>`;
    sendingToast.classList.add('bg-info');
    toastContainer.appendChild(sendingToast);
    new bootstrap.Toast(sendingToast,{delay:3000}).show();

    const formData = new FormData(this);
    let data;
    try {
        const res = await fetch("{{ route('register') }}", {
            method:'POST',
            headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}' },
            body: formData
        });
        data = await res.json();
    } catch(e){
        alert('Server error occurred. Check logs.');
        submitBtn.disabled=false;
        submitBtn.innerText='Create Account';
        return;
    }

    sendingToast.remove();

    const toastEl=document.createElement('div');
    toastEl.className='toast align-items-center text-white border-0';
    toastEl.innerHTML=`<div class="d-flex"><div class="toast-body">${data.message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    toastContainer.appendChild(toastEl);

    if(data.status==='success'){
        toastEl.classList.add('bg-success');
        new bootstrap.Toast(toastEl,{delay:7000}).show();
        this.reset();
        document.getElementById('roleInput').value='advertiser';
        document.querySelectorAll('#roleSelect .role-card').forEach(c=>{
            const isAdv = c.dataset.value === 'advertiser';
            c.classList.toggle('selected', isAdv);
            c.setAttribute('aria-checked', isAdv ? 'true' : 'false');
        });
    } else if(data.status==='error'){
        toastEl.classList.add('bg-danger');
        new bootstrap.Toast(toastEl,{delay:5000}).show();
    } else if(data.status==='validation'){
        for(let key in data.errors){
            const input=document.querySelector(`[name="${key}"]`);
            const errorDiv=document.getElementById(key+'Error');
            if(input) input.classList.add('is-invalid');
            if(errorDiv) errorDiv.innerText=data.errors[key][0];
        }
    }

    submitBtn.disabled=false;
    submitBtn.innerText='Create Account';
});
</script>
@endsection
