@extends('layouts.app')

@php
    $welcomeBonusEnabled = $welcomeBonusEnabled ?? false;
    $welcomeBonusAmount = isset($welcomeBonusAmount) ? (float) $welcomeBonusAmount : 0.0;
    $welcomeBonusEuro = '€'.rtrim(rtrim(number_format($welcomeBonusAmount, 2, '.', ''), '0'), '.');
@endphp
@section('title', function_exists('welcome_bonus_message') ? welcome_bonus_message('meta_register_title', 'meta_register_title_off') : 'Create Account | SEOLinkBuildings')
@section('description', function_exists('welcome_bonus_message') ? welcome_bonus_message('meta_register_description', 'meta_register_description_off') : 'Create a free SEOLinkBuildings account.')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

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
                                    <img src="{{ asset('assets/img/logo.svg') }}?v={{ @filemtime(public_path('assets/img/logo.svg')) ?: '1' }}"
                                         alt="SEOLinkBuildings"
                                         width="220"
                                         height="48">
                                </div>

                                <div class="auth-panel-kicker">Start free today</div>
                                <h1 class="auth-panel-title">One account, both roles</h1>
                                <p class="auth-panel-copy">
                                    Every account includes Advertiser and Publisher workspaces. Choose where you want to start — you can switch anytime.
                                </p>

                                <ul class="auth-proof-list" id="authProofList" aria-label="What you get with SEOLinkBuildings">
                                    @if($welcomeBonusEnabled)
                                    <li class="benefit-advertiser">
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M12 22V12"/><path d="M2.5 9.5h19v2.5H2.5z"/><path d="M7.5 9.5C6 9.5 5 8.2 5 7.2S6.2 5 7.5 5c2 0 2.5 2 4.5 4.5C14 7 14.5 5 16.5 5 17.8 5 19 6.2 19 7.2s-1 2.3-2.5 2.3"/></svg>
                                        </span>
                                        <div>
                                            <strong>{{ $welcomeBonusEuro }} welcome credit</strong>
                                            <span>Spend on your first orders — not withdrawable</span>
                                        </div>
                                    </li>
                                    @endif
                                    <li class="benefit-advertiser">
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L18 7"/></svg>
                                        </span>
                                        <div>
                                            <strong>Free SEO audit</strong>
                                            <span>Backlink quality, authority, and next steps</span>
                                        </div>
                                    </li>
                                    <li class="benefit-publisher d-none">
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 9h8"/><path d="M8 13h5"/></svg>
                                        </span>
                                        <div>
                                            <strong>List your sites</strong>
                                            <span>Set prices, niches, and delivery times</span>
                                        </div>
                                    </li>
                                    <li class="benefit-publisher d-none">
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        <div>
                                            <strong>Paid placements</strong>
                                            <span>Receive briefed orders and get paid via wallet</span>
                                        </div>
                                    </li>
                                    <li>
                                        <span class="auth-proof-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        <div>
                                            <strong>Verified network</strong>
                                            <span>EU &amp; major markets — no PBNs</span>
                                        </div>
                                    </li>
                                </ul>

                                <blockquote class="auth-quote" id="authQuote">
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
                                <strong id="mobileBenefitTitle">{{ $welcomeBonusEnabled ? 'Start with '.$welcomeBonusEuro.' free credit' : 'Free to start — no card required' }}</strong>
                                <ul id="mobileBenefitList">
                                    @if($welcomeBonusEnabled)
                                    <li class="benefit-advertiser">
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M12 22V12"/><path d="M2.5 9.5h19v2.5H2.5z"/><path d="M7.5 9.5C6 9.5 5 8.2 5 7.2S6.2 5 7.5 5c2 0 2.5 2 4.5 4.5C14 7 14.5 5 16.5 5 17.8 5 19 6.2 19 7.2s-1 2.3-2.5 2.3"/></svg>
                                        </span>
                                        Welcome bonus for first orders
                                    </li>
                                    @endif
                                    <li class="benefit-advertiser">
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M7 15l3.5-4 3 2.5L18 7"/></svg>
                                        </span>
                                        Free SEO audit on signup
                                    </li>
                                    <li class="benefit-publisher d-none">
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 9h8"/><path d="M8 13h5"/></svg>
                                        </span>
                                        List sites and set your prices
                                    </li>
                                    <li class="benefit-publisher d-none">
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        Get paid for quality placements
                                    </li>
                                    <li>
                                        <span class="mi" aria-hidden="true">
                                            <svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9.5 12.2l1.8 1.8 3.7-3.8"/></svg>
                                        </span>
                                        Both roles included on every account
                                    </li>
                                </ul>
                            </div>

                            <form id="registerForm" novalidate>
                                @csrf

                                <div class="mb-3">
                                    <label for="name" class="auth-label">Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" class="form-control auth-input" placeholder="Your full name" autocomplete="name" required aria-describedby="nameError">
                                    <div class="invalid-feedback" id="nameError" role="alert" aria-live="polite"></div>
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="auth-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" id="email" class="form-control auth-input" placeholder="you@company.com" autocomplete="email" required aria-describedby="emailError">
                                    <div class="invalid-feedback" id="emailError" role="alert" aria-live="polite"></div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-12 col-xl-6">
                                        <label for="password" class="auth-label">Password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" name="password" id="password" class="form-control auth-input pe-5" placeholder="Create a password" autocomplete="new-password" required aria-describedby="passwordError">
                                            <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password', this)" aria-label="Show or hide password"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                        <div class="invalid-feedback" id="passwordError" role="alert" aria-live="polite"></div>
                                    </div>
                                    <div class="col-12 col-xl-6">
                                        <label for="password_confirmation" class="auth-label">Confirm password <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control auth-input pe-5" placeholder="Repeat password" autocomplete="new-password" required aria-describedby="password_confirmationError">
                                            <button type="button" class="input-group-text" style="cursor:pointer" onclick="togglePassword('password_confirmation', this)" aria-label="Show or hide password confirmation"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                        <div class="invalid-feedback" id="password_confirmationError" role="alert" aria-live="polite"></div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="auth-label">Register as <span class="text-danger">*</span></label>
                                    <p class="small text-muted mb-2" id="roleHint">
                                        Choose your starting workspace — you can switch roles later from your account.
                                    </p>
                                    <div class="auth-role-grid" id="roleSelect" role="radiogroup" aria-label="Choose account type" aria-describedby="roleHint">
                                        <div class="auth-role-card role-card selected" data-value="advertiser" role="radio" aria-checked="true" tabindex="0">
                                            <i class="fa-solid fa-bullseye role-main" aria-hidden="true"></i>
                                            Advertiser
                                            <i class="fa-solid fa-check role-check" aria-hidden="true"></i>
                                        </div>
                                        <div class="auth-role-card role-card" data-value="publisher" role="radio" aria-checked="false" tabindex="-1">
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
                                        <input type="checkbox" class="form-check-input" name="terms" id="terms" value="1" required>
                                        <label class="form-check-label" for="terms">
                                            <span class="text-danger">*</span> I agree to the <a href="{{ route('terms-of-services') }}" target="_blank" rel="noopener" class="auth-meta-link">Terms of Service</a>.
                                        </label>
                                        <div class="invalid-feedback d-block" id="termsError"></div>
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

                                <div class="auth-social-stack mb-2">
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
<div class="slb-toast-stack" id="toastContainer"></div>

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

function updateRoleBenefits(role){
    const isPublisher = role === 'publisher';
    const welcomeBonusEnabled = @json($welcomeBonusEnabled);
    const welcomeBonusEuro = @json($welcomeBonusEuro);
    document.querySelectorAll('.benefit-advertiser').forEach(el => {
        el.classList.toggle('d-none', isPublisher);
    });
    document.querySelectorAll('.benefit-publisher').forEach(el => {
        el.classList.toggle('d-none', !isPublisher);
    });

    const mobileTitle = document.getElementById('mobileBenefitTitle');
    if (mobileTitle) {
        mobileTitle.textContent = isPublisher
            ? 'Earn from your sites'
            : (welcomeBonusEnabled ? 'Start with ' + welcomeBonusEuro + ' free credit' : 'Free to start — no card required');
    }

    const quote = document.getElementById('authQuote');
    if (quote) {
        quote.innerHTML = isPublisher
            ? 'List verified inventory, receive briefed orders, and get paid through your wallet.<cite>SEOLinkBuildings marketplace</cite>'
            : 'Join advertisers who buy placements with clear pricing and tracked delivery.<cite>SEOLinkBuildings marketplace</cite>';
    }
}

function selectRoleCard(card){
    const cards = document.querySelectorAll('#roleSelect .role-card');
    cards.forEach(c=>{
        c.classList.remove('selected');
        c.setAttribute('aria-checked', 'false');
        c.setAttribute('tabindex', '-1');
    });
    card.classList.add('selected');
    card.setAttribute('aria-checked', 'true');
    card.setAttribute('tabindex', '0');
    document.getElementById('roleInput').value = card.dataset.value;
    updateRoleBenefits(card.dataset.value);
    card.focus();
}

document.querySelectorAll('#roleSelect .role-card').forEach(card=>{
    card.addEventListener('click', function(){
        selectRoleCard(this);
    });
    card.addEventListener('keydown', function(e){
        const cards = Array.from(document.querySelectorAll('#roleSelect .role-card'));
        const idx = cards.indexOf(this);
        if(e.key === 'Enter' || e.key === ' '){
            e.preventDefault();
            selectRoleCard(this);
            return;
        }
        if(e.key === 'ArrowRight' || e.key === 'ArrowDown'){
            e.preventDefault();
            selectRoleCard(cards[(idx + 1) % cards.length]);
            return;
        }
        if(e.key === 'ArrowLeft' || e.key === 'ArrowUp'){
            e.preventDefault();
            selectRoleCard(cards[(idx - 1 + cards.length) % cards.length]);
        }
    });
});

(function () {
    const form = document.getElementById('registerForm');
    const submitBtn = document.getElementById('submitBtn');
    if (!form || !submitBtn) return;

    const csrfToken = form.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content
        || '{{ csrf_token() }}';

    function showToast(message, type = 'danger') {
        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'slb-toast-stack';
            toastContainer.style.zIndex = '1200';
            document.body.appendChild(toastContainer);
        }
        const solid = type === 'success' || type === 'danger' || type === 'primary';
        const bg = type === 'info' ? 'bg-info' : (type === 'warning' ? 'bg-warning' : ('bg-' + type));
        const textClass = solid ? 'text-white' : 'text-dark';
        const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close';
        const toast = document.createElement('div');
        toast.setAttribute('role', type === 'success' ? 'status' : 'alert');
        toast.setAttribute('aria-live', type === 'success' ? 'polite' : 'assertive');
        toast.className = `toast align-items-center ${textClass} border-0 ${bg}`;
        toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="${closeClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
        toastContainer.appendChild(toast);
        if (window.bootstrap && bootstrap.Toast) {
            new bootstrap.Toast(toast, { delay: 7000 }).show();
        } else {
            toast.classList.add('show');
            setTimeout(() => toast.remove(), 7000);
        }
    }

    function resetSubmitButton() {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Create Account';
    }

    async function handleRegisterSubmit(e) {
        if (e) e.preventDefault();
        if (submitBtn.disabled) return;

        submitBtn.disabled = true;
        submitBtn.innerText = 'Creating account...';

        document.querySelectorAll('.form-control, .form-check-input').forEach(input => {
            input.classList.remove('is-invalid');
        });

        ['nameError','emailError','passwordError','password_confirmationError','roleError','termsError'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.innerText = '';
                el.classList.remove('d-block');
            }
        });

        const role = document.getElementById('roleInput')?.value;
        if (!role) {
            showToast('Please select a role.', 'warning');
            resetSubmitButton();
            return;
        }

        if (!document.getElementById('terms')?.checked) {
            document.getElementById('terms')?.classList.add('is-invalid');
            const termsError = document.getElementById('termsError');
            if (termsError) termsError.innerText = 'You must agree to the Terms and Services.';
            showToast('Please accept the Terms of Service to continue.', 'warning');
            resetSubmitButton();
            return;
        }

        const formData = new FormData(form);
        let data;
        try {
            const res = await fetch(@json('/register'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            });

            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                const fallback = (typeof slbHttpMessage === 'function')
                    ? slbHttpMessage({ status: res.status }, 'Please refresh and try again.')
                    : (res.status === 419
                        ? 'Your session expired. Refresh the page and try again.'
                        : 'Please refresh and try again.');
                throw new Error(fallback);
            }
            data = await res.json();
            if (res.status === 419 && (!data.status || data.status === 'error')) {
                showToast(data.message || 'Your session expired. Refresh the page and try again.', 'danger');
                resetSubmitButton();
                return;
            }
        } catch (err) {
            showToast((err && err.message) ? err.message : 'Server error occurred. Please refresh and try again.', 'danger');
            resetSubmitButton();
            return;
        }

        if (data.status === 'success') {
            showToast(data.message || 'Registration successful!', 'success');
            submitBtn.innerText = 'Redirecting…';
            const redirectTo = data.redirect || @json(url('/login'));
            setTimeout(() => { window.location.href = redirectTo; }, 900);
            return;
        }

        if (data.status === 'validation') {
            const errors = data.errors || {};
            const messages = [];
            for (const key in errors) {
                const input = form.querySelector(`[name="${key}"]`);
                const errorDiv = document.getElementById(key + 'Error');
                if (input) input.classList.add('is-invalid');
                if (errorDiv) {
                    errorDiv.innerText = errors[key][0];
                    errorDiv.classList.add('d-block');
                }
                if (errors[key][0]) messages.push(errors[key][0]);
            }
            showToast(messages[0] || data.message || 'Please fix the highlighted fields.', 'warning');
        } else {
            showToast(data.message || 'Something went wrong. Please try again.', 'danger');
        }

        resetSubmitButton();
    }

    form.addEventListener('submit', handleRegisterSubmit);

    updateRoleBenefits(document.getElementById('roleInput')?.value || 'advertiser');
})();
</script>
@endsection
