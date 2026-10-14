@extends('layouts.app')

@php
    $pendingGoogle = $pendingGoogle ?? [];
    $pendingName = (string) ($pendingGoogle['name'] ?? '');
    $pendingEmail = (string) ($pendingGoogle['email'] ?? '');
@endphp
@section('title', 'Finish creating your account | SEOLinkBuildings')
@section('description', 'Choose your country to finish creating your SEOLinkBuildings account.')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="auth-page">
    <div class="container auth-shell">
        <div class="row justify-content-center">
            <div class="col-xl-8">
                <div class="auth-card">
                    <div class="auth-form-col p-4 p-md-5">
                        <h2 class="auth-form-title">Finish your Google account</h2>
                        <p class="auth-form-sub">We still need your country so we can set the right account currency.</p>

                        @if($pendingName !== '' || $pendingEmail !== '')
                            <p class="small text-muted mb-3">
                                Signed in with Google as
                                <strong>{{ $pendingName !== '' ? $pendingName : $pendingEmail }}</strong>
                                @if($pendingEmail !== '' && $pendingName !== '')
                                    ({{ $pendingEmail }})
                                @endif
                            </p>
                        @endif

                        <form method="POST" action="{{ route('auth.google.complete.store') }}" novalidate>
                            @csrf

                            <div class="mb-3">
                                <label class="auth-label">Register as <span class="text-danger">*</span></label>
                                <p class="small text-muted mb-2">Choose your starting workspace — you can switch roles later from your account.</p>
                                <div class="auth-role-grid" id="roleSelect" role="radiogroup" aria-label="Choose account type">
                                    <div class="auth-role-card role-card {{ old('role', 'advertiser') === 'advertiser' ? 'selected' : '' }}" data-value="advertiser" role="radio" aria-checked="{{ old('role', 'advertiser') === 'advertiser' ? 'true' : 'false' }}" tabindex="0">
                                        <i class="fa-solid fa-bullseye role-main" aria-hidden="true"></i>
                                        Advertiser
                                        <i class="fa-solid fa-check role-check" aria-hidden="true"></i>
                                    </div>
                                    <div class="auth-role-card role-card {{ old('role') === 'publisher' ? 'selected' : '' }}" data-value="publisher" role="radio" aria-checked="{{ old('role') === 'publisher' ? 'true' : 'false' }}" tabindex="-1">
                                        <i class="fa-solid fa-file-lines role-main" aria-hidden="true"></i>
                                        Publisher
                                        <i class="fa-solid fa-check role-check" aria-hidden="true"></i>
                                    </div>
                                </div>
                                <input type="hidden" name="role" id="roleInput" value="{{ old('role', 'advertiser') }}">
                                <div class="invalid-feedback d-block">{{ $errors->first('role') }}</div>
                            </div>

                            @include('auth.partials.country-field')

                            <div class="mb-3">
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input @error('terms') is-invalid @enderror" name="terms" id="terms" value="1" required {{ old('terms') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="terms">
                                        <span class="text-danger">*</span> I agree to the <a href="{{ route('terms-of-services') }}" target="_blank" rel="noopener" class="auth-meta-link">Terms of Service</a>.
                                    </label>
                                    <div class="invalid-feedback d-block">{{ $errors->first('terms') }}</div>
                                </div>
                            </div>

                            <button type="submit" class="auth-cta w-100" id="submitBtn">Create Account</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('#roleSelect .role-card').forEach(card => {
    card.addEventListener('click', function () {
        document.querySelectorAll('#roleSelect .role-card').forEach(c => {
            c.classList.remove('selected');
            c.setAttribute('aria-checked', 'false');
        });
        this.classList.add('selected');
        this.setAttribute('aria-checked', 'true');
        document.getElementById('roleInput').value = this.dataset.value;
    });
});

(function () {
    const countrySelect = document.getElementById('country');
    const currencyPreview = document.getElementById('signupCurrencyPreview');
    function currencyForCountry(code) {
        const c = String(code || '').toLowerCase();
        if (c === 'uk' || c === 'gb') return { symbol: '£', currency: 'GBP' };
        if (c === 'us') return { symbol: '$', currency: 'USD' };
        return { symbol: '€', currency: 'EUR' };
    }
    function refreshCurrencyPreview() {
        if (!currencyPreview) return;
        const chosen = currencyForCountry(countrySelect?.value);
        currencyPreview.textContent = 'Your account currency: ' + chosen.symbol + ' ' + chosen.currency;
    }
    if (countrySelect) {
        countrySelect.addEventListener('change', refreshCurrencyPreview);
        refreshCurrencyPreview();
    }
})();
</script>
@endsection
