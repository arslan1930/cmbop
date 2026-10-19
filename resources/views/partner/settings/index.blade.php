@extends('partner.layouts.app')
@section('title', 'Settings')
@section('content')
    <h1 class="partner-h1">Settings</h1>
    <p class="partner-lead">English only. Security and payout emails cannot be switched off.</p>

    <div class="partner-card">
        <h2>Name</h2>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.name') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>Name
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
                </label>
                <button class="partner-btn" type="submit">Save name</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Password</h2>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.password') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>Current password
                    <input type="password" name="current_password" required>
                </label>
                <label>New password (12+ characters)
                    <input type="password" name="password" required>
                </label>
                <label>Confirm new password
                    <input type="password" name="password_confirmation" required>
                </label>
                <button class="partner-btn" type="submit">Change password</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Email</h2>
        <p>Current address: <strong>{{ $user->email }}</strong></p>
        @if ($pendingEmail)
            <p>Pending confirmation for <strong>{{ $pendingEmail }}</strong>. Check that inbox. The change applies only after you confirm.</p>
        @endif
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.email') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label>New email
                    <input type="email" name="email" value="{{ old('email') }}" required>
                </label>
                <label>Current password
                    <input type="password" name="current_password" required>
                </label>
                <button class="partner-btn" type="submit">Request email change</button>
            </div>
        </form>
    </div>

    <div class="partner-card">
        <h2>Notifications</h2>
        <form method="post" action="{{ \App\Support\PartnerDashboard::route('settings.notifications') }}" class="partner-form">
            @csrf
            <div class="stack">
                <label><input type="checkbox" name="new_order" value="1" @checked(($prefs['new_order'] ?? true))> Email me about new orders</label>
                <label><input type="checkbox" name="correction" value="1" @checked(($prefs['correction'] ?? true))> Email me about corrections</label>
                <label><input type="checkbox" name="one_click_links" value="1" @checked($oneClick)> Allow one-click order links in email</label>
                <p class="partner-lead">Payout released and security emails always send.</p>
                <button class="partner-btn" type="submit">Save notifications</button>
            </div>
        </form>
    </div>
@endsection
