@php
    $staffTwoFactor = app(\App\Services\Auth\StaffTwoFactorService::class);
    $staffUser = auth()->user();
    $isStaff = $staffUser && $staffTwoFactor->holdsStaffRole($staffUser);
    $twoFactorOn = $isStaff && $staffTwoFactor->isConfirmed($staffUser);
    $twoFactorRow = $isStaff ? $staffTwoFactor->record($staffUser) : null;
    $twoFactorPending = $isStaff && $twoFactorRow && is_string($twoFactorRow->secret) && $twoFactorRow->secret !== '' && ! $twoFactorOn;
    $twoFactorQr = $twoFactorPending ? $staffTwoFactor->qrDataUri($twoFactorRow, $staffUser) : null;
    $recoveryCodes = session('staff_2fa_recovery_codes');
@endphp
@if($isStaff)
<div class="col-12" id="staff-two-factor">
    <div class="profile-card">
        <div class="card-header-custom">
            <i class="fas fa-shield-alt text-primary"></i> Two-factor authentication
            @if($twoFactorOn)
                <span class="badge bg-success ms-2">On</span>
            @else
                <span class="badge bg-secondary ms-2">Off</span>
            @endif
        </div>
        <div class="card-body-custom">
            <p class="text-muted small mb-3">
                Admin and marketing sign-in can require a code from an authenticator app
                (Google Authenticator, 1Password, Authy). Advertiser and publisher logins are unchanged.
            </p>

            @if(is_array($recoveryCodes) && $recoveryCodes !== [])
                <div class="alert alert-warning" role="status">
                    <strong>Save these backup codes now.</strong> Each code works once. They will not be shown again.
                    <ul class="mb-0 mt-2 font-monospace">
                        @foreach($recoveryCodes as $backupCode)
                            <li>{{ substr($backupCode, 0, 5) }}-{{ substr($backupCode, 5) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($twoFactorOn)
                <p class="small mb-3">Sign-in asks for an app code after your password. Keep backup codes somewhere safe.</p>
                <form method="POST" action="{{ route('profile.two-factor.recovery') }}" class="d-flex flex-wrap gap-2 align-items-end mb-3">
                    @csrf
                    <div>
                        <label class="form-label small mb-1" for="staffTwoFactorRegenCode">App code</label>
                        <input type="text" name="code" id="staffTwoFactorRegenCode" class="form-control form-control-sm" required maxlength="16" autocomplete="one-time-code" placeholder="123456">
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-secondary"
                            data-slb-confirm="Replace backup codes? Old codes stop working immediately."
                            data-slb-confirm-title="New backup codes?"
                            data-slb-confirm-text="Replace codes">
                        Replace backup codes
                    </button>
                </form>
                <form method="POST" action="{{ route('profile.two-factor.disable') }}" class="row g-2 align-items-end"
                      data-slb-confirm="Turn off two-factor authentication? Staff sign-in will only need a password until you set it up again."
                      data-slb-confirm-title="Turn off two-factor?"
                      data-slb-confirm-text="Turn off"
                      data-slb-confirm-danger="1">
                    @csrf
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="staffTwoFactorDisablePassword">Current password</label>
                        <input type="password" name="current_password" id="staffTwoFactorDisablePassword" class="form-control form-control-sm" required autocomplete="current-password">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="staffTwoFactorDisableCode">App or backup code</label>
                        <input type="text" name="code" id="staffTwoFactorDisableCode" class="form-control form-control-sm" required maxlength="32" autocomplete="one-time-code">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Turn off</button>
                    </div>
                </form>
            @elseif($twoFactorPending)
                <p class="small mb-2">Scan this QR code in your authenticator app, then enter the 6-digit code to finish.</p>
                @if($twoFactorQr)
                    <img src="{{ $twoFactorQr }}" alt="Two-factor QR code" width="180" height="180" class="d-block mb-2 border rounded">
                @endif
                <p class="small mb-3">Can’t scan? Enter this key: <code class="user-select-all">{{ $twoFactorRow->secret }}</code></p>
                <form method="POST" action="{{ route('profile.two-factor.confirm') }}" class="d-flex flex-wrap gap-2 align-items-end">
                    @csrf
                    <div>
                        <label class="form-label small mb-1" for="staffTwoFactorConfirmCode">6-digit code</label>
                        <input type="text" name="code" id="staffTwoFactorConfirmCode" class="form-control form-control-sm" required inputmode="numeric" maxlength="16" autocomplete="one-time-code" placeholder="123456">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Confirm and enable</button>
                </form>
            @else
                <form method="POST" action="{{ route('profile.two-factor.start') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">Set up two-factor authentication</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endif
