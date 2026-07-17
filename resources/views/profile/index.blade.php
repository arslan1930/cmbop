@extends('layouts.app')

@section('title', 'Profile - SEOLinkBuildings')

@push('styles')
<style>
    :root {
        --card-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
        --card-hover: 0 4px 12px rgba(0,0,0,.08), 0 8px 32px rgba(0,0,0,.05);
        --radius: 12px;
        --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    /* ...existing styles unchanged... */
</style>
@endpush

@section('content')
<div class="container py-5">

    {{-- ─── FLASH MESSAGES (pill style) ─── --}}
    @if(session('success'))
    <div class="alert alert-success border-0 rounded-pill py-2 px-3 d-inline-flex align-items-center gap-2 fade show"
         role="alert" id="flashSuccess">
        <i class="fas fa-check-circle"></i>
        <span>{{ session('success') }}</span>
        <button type="button" class="btn-close btn-close-white btn-sm ms-1" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger border-0 rounded-pill py-2 px-3 d-inline-flex align-items-center gap-2 fade show"
         role="alert" id="flashError">
        <i class="fas fa-exclamation-circle"></i>
        <span>{{ session('error') }}</span>
        <button type="button" class="btn-close btn-close-white btn-sm ms-1" data-bs-dismiss="alert"></button>
    </div>
    @endif

    
    <div class="row g-4">

        {{-- ══ PROFILE INFO ══ --}}
        <div class="col-lg-6">
            <div class="profile-card">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-user-circle text-primary"></i> Profile Information</span>
                    <a href="{{ route('profile.notifications') }}" class="btn btn-sm btn-outline-primary">Email Preferences</a>
                </div>
                <div class="card-body-custom">
                    <form method="POST" action="{{ route('profile.update') }}" id="formProfile">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6 form-group">
                                <label>Full Name</label>
                                <input type="text" name="name" class="form-control form-control-sm"
                                       value="{{ old('name', auth()->user()->name) }}" autocomplete="name"
                                       placeholder="Your display name">
                                @error('name')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Email <span class="text-muted">(read-only)</span></label>
                                <input type="email" class="form-control form-control-sm bg-light"
                                       value="{{ auth()->user()->email }}" disabled>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control form-control-sm"
                                       value="{{ old('phone', auth()->user()->phone) }}" autocomplete="tel"
                                       placeholder="+1 (555) 000-0000">
                                @error('phone')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check mt-3 mb-0">
                            <input class="form-check-input" type="checkbox" id="confirmProfile" required>
                            <label class="form-check-label fs-xs text-muted" for="confirmProfile">
                                I confirm that the information provided is accurate
                            </label>
                        </div>

                        <div class="d-flex align-items-center gap-2 mt-3">
                            <button class="btn btn-primary btn-profile" type="submit">
                                <span class="spinner-border spinner-border-sm d-none me-1" role="status"></span>
                                <i class="fas fa-save me-1"></i> Save Changes
                            </button>
                            <span class="save-status" id="saveProfileStatus"></span>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ══ CHANGE PASSWORD ══ --}}
        <div class="col-lg-6">
            <div class="profile-card">
                <div class="card-header-custom">
                    <i class="fas fa-lock text-primary"></i> Change Password
                </div>
                <div class="card-body-custom">
                    <form method="POST" action="{{ route('profile.password') }}" id="formPassword">
                        @csrf
                        <div class="form-group">
                            <label>Current Password</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-transparent border-end-0">
                                    <i class="fas fa-key text-muted"></i>
                                </span>
                                <input type="password" name="current_password"
                                       class="form-control border-start-0 ps-0"
                                       autocomplete="current-password" required placeholder="Enter current password">
                                <button type="button" class="btn btn-outline-secondary border-start-0 toggle-password"
                                        tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            @error('current_password')
                                <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-md-6 form-group">
                                <label>New Password</label>
                                <input type="password" name="password"
                                       class="form-control form-control-sm" autocomplete="new-password"
                                       required minlength="8" placeholder="Min. 8 characters">
                                @error('password')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                                <div class="validation-feedback text-danger">
                                    Must be at least 8 characters
                                </div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="password_confirmation"
                                       class="form-control form-control-sm" autocomplete="new-password"
                                       required placeholder="Re-enter new password">
                            </div>
                        </div>

                        {{-- Password strength bar --}}
                        <div class="password-strength mt-1 d-none" id="pwStrength">
                            <div class="d-flex gap-1 mb-1">
                                <div class="strength-bar flex-fill rounded" data-index="0"></div>
                                <div class="strength-bar flex-fill rounded" data-index="1"></div>
                                <div class="strength-bar flex-fill rounded" data-index="2"></div>
                                <div class="strength-bar flex-fill rounded" data-index="3"></div>
                            </div>
                            <small class="strength-label fs-xs text-muted"></small>
                        </div>

                        <button class="btn btn-primary btn-profile mt-3" type="submit">
                            <i class="fas fa-shield-alt me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- ══ SOCIAL LINKS ══ --}}
        <div class="col-lg-6">
            <div class="profile-card">
                <div class="card-header-custom">
                    <i class="fas fa-share-alt text-info"></i> Social Media Links
                    <span class="badge bg-light text-muted ms-auto fs-xs fw-normal">Optional</span>
                </div>
                <div class="card-body-custom">
                    <form method="POST" action="{{ route('profile.social') }}" id="formSocial">
                        @csrf
                        <div class="form-group">
                            <label><i class="fab fa-facebook text-primary me-1"></i>Facebook</label>
                            <input type="url" name="facebook" class="form-control form-control-sm"
                                   value="{{ old('facebook', auth()->user()->facebook) }}" placeholder="https://facebook.com/yourpage">
                            @error('facebook')
                                <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label><i class="fab fa-twitter text-info me-1"></i>Twitter / X</label>
                            <input type="url" name="twitter" class="form-control form-control-sm"
                                   value="{{ old('twitter', auth()->user()->twitter) }}" placeholder="https://x.com/yourhandle">
                            @error('twitter')
                                <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label><i class="fab fa-linkedin text-primary me-1"></i>LinkedIn</label>
                            <input type="url" name="linkedin" class="form-control form-control-sm"
                                   value="{{ old('linkedin', auth()->user()->linkedin) }}" placeholder="https://linkedin.com/in/you">
                            @error('linkedin')
                                <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <button class="btn btn-outline-secondary btn-profile mt-2" type="submit">
                            <i class="fas fa-link me-1"></i> Save Links
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- ══ BILLING ══ --}}
        <div class="col-lg-6">
            <div class="profile-card">
                <div class="card-header-custom">
                    <i class="fas fa-file-invoice text-success"></i> Billing Information
                </div>
                <div class="card-body-custom">
                    <form method="POST" action="{{ route('profile.billing') }}" id="formBilling">
                        @csrf

                        <div class="row g-2">
                            <div class="col-md-6 form-group">
                                <label>Billing Name</label>
                                <input type="text" name="billing_name" class="form-control form-control-sm"
                                       value="{{ old('billing_name', auth()->user()->billing_name) }}" autocomplete="name">
                                @error('billing_name')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 form-group">
                                <label class="d-flex align-items-center gap-1">
                                    Company Name
                                    <i class="fas fa-info-circle hint-icon"
                                       data-bs-toggle="tooltip"
                                       title="{{ auth()->user()->company_name
                                           ? 'Locked — contact support to change.'
                                           : 'Can be set only once.' }}">
                                    </i>
                                </label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text input-lock-icon
                                        {{ auth()->user()->company_name ? 'text-danger' : 'text-muted' }}">
                                        <i class="fas fa-{{ auth()->user()->company_name ? 'lock' : 'building' }}"></i>
                                    </span>
                                    <input type="text" name="company_name" class="form-control"
                                           value="{{ old('company_name', auth()->user()->company_name) }}"
                                           {{ auth()->user()->company_name ? 'readonly' : '' }}>
                                </div>
                                @error('company_name')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4 form-group">
                                <label>Country</label>
                                <input type="text" name="country" class="form-control form-control-sm"
                                       value="{{ old('country', auth()->user()->country) }}" autocomplete="country">
                                @error('country')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4 form-group">
                                <label>State / Province</label>
                                <input type="text" name="state" class="form-control form-control-sm"
                                       value="{{ old('state', auth()->user()->state) }}" autocomplete="address-level1">
                                @error('state')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4 form-group">
                                <label>City</label>
                                <input type="text" name="city" class="form-control form-control-sm"
                                       value="{{ old('city', auth()->user()->city) }}" autocomplete="address-level2">
                                @error('city')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Postal Code</label>
                                <input type="text" name="postal_code" class="form-control form-control-sm"
                                       value="{{ old('postal_code', auth()->user()->postal_code) }}" autocomplete="postal-code">
                                @error('postal_code')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4 form-group">
                                <label>VAT Number</label>
                                <input type="text" name="vat_number" class="form-control form-control-sm"
                                       value="{{ old('vat_number', auth()->user()->vat_number) }}" placeholder="Optional">
                                @error('vat_number')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12 form-group">
                                <label>Street Address</label>
                                <input type="text" name="address" class="form-control form-control-sm"
                                       value="{{ old('address', auth()->user()->address) }}" autocomplete="street-address">
                                @error('address')
                                    <div class="text-danger fs-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check mt-3 mb-0">
                            <input class="form-check-input" type="checkbox" id="confirmBilling" required>
                            <label class="form-check-label fs-xs text-muted" for="confirmBilling">
                                I confirm that the billing information is accurate
                            </label>
                        </div>

                        <div class="d-flex align-items-center gap-2 mt-3">
                            <button class="btn btn-primary btn-profile" type="submit">
                                <i class="fas fa-file-invoice me-1"></i> Save Billing
                            </button>
                            <span class="save-status" id="saveBillingStatus"></span>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {

    // ── Tooltips ──
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(t => new bootstrap.Tooltip(t, { delay: { show: 150, hide: 50 } }));

    // ── Password visibility toggle ──
    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });

    // ── Password strength meter ──
    const pwInput = document.querySelector('input[name="password"]');
    const pwStrength = document.getElementById('pwStrength');
    const bars = pwStrength?.querySelectorAll('.strength-bar');
    const label = pwStrength?.querySelector('.strength-label');

    const strengthLabels = ['Too weak', 'Weak', 'Fair', 'Strong'];
    const strengthColors = ['#e53e3e', '#d69e2e', '#38a169', '#2b6cb0'];

    pwInput?.addEventListener('input', function() {
        const val = this.value;
        if (val.length === 0) {
            pwStrength.classList.add('d-none');
            return;
        }
        pwStrength.classList.remove('d-none');

        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        score = Math.min(score, 3);

        bars.forEach((b, i) => {
            b.style.background = i <= score ? strengthColors[score] : '#e2e8f0';
            b.style.height = i <= score ? '5px' : '3px';
        });
        label.textContent = strengthLabels[score];
        label.style.color = strengthColors[score];
    });

    // ── Auto-save status indicators ──
    function setupFormStatus(formId, statusId) {
        const form = document.getElementById(formId);
        if (!form) return;
        const btn = form.querySelector('button[type="submit"]');
        const spinner = btn?.querySelector('.spinner-border');
        const status = document.getElementById(statusId);

        form.addEventListener('submit', function(e) {
            // native validation first
            if (!form.checkValidity()) return;

            if (spinner) spinner.classList.remove('d-none');
            btn.disabled = true;
            btn.innerHTML = btn.innerHTML.replace(/fa-.+?me-1/, 'spinner-border spinner-border-sm me-1');

            if (status) {
                status.textContent = 'Saving…';
                status.className = 'save-status saving';
            }
        });

        // AJAX feedback simulation (replace with real AJAX if preferred)
        // If using normal POST, flash message handles it.
    }
    setupFormStatus('formProfile', 'saveProfileStatus');
    setupFormStatus('formBilling', 'saveBillingStatus');

    // ── Auto-dismiss flash messages ──
    const flashMsgs = document.querySelectorAll('.alert');
    flashMsgs.forEach(msg => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(msg);
            bsAlert.close();
        }, 5500);
    });

    // ── Confirm checkbox UX: enable button only when checked ──
    document.querySelectorAll('form').forEach(form => {
        const checkbox = form.querySelector('.form-check-input[type="checkbox"]');
        const submitBtn = form.querySelector('button[type="submit"]');
        if (checkbox && submitBtn) {
            submitBtn.disabled = !checkbox.checked;
            checkbox.addEventListener('change', () => {
                submitBtn.disabled = !checkbox.checked;
            });
        }
    });
});
</script>
@endpush