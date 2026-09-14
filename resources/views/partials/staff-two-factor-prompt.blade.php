@php
    $staffTwoFactor = app(\App\Services\Auth\StaffTwoFactorService::class);
    $staffUser = auth()->user();
    $showStaffTwoFactorPrompt = $staffUser
        && $staffTwoFactor->holdsStaffRole($staffUser)
        && ! $staffTwoFactor->isConfirmed($staffUser);
@endphp
@if($showStaffTwoFactorPrompt)
    <div class="alert alert-info d-flex align-items-start gap-2" role="status">
        <i class="fa fa-shield-alt mt-1" aria-hidden="true"></i>
        <div>
            <strong>Protect staff sign-in.</strong>
            Turn on two-factor authentication so admin and marketing logins need an app code after the password.
            <a href="{{ route('profile') }}#staff-two-factor" class="alert-link">Set it up on your profile</a>.
        </div>
    </div>
@endif
