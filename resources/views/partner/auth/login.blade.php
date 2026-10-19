@extends('partner.layouts.guest')
@section('title', 'Partner sign in')
@section('content')
    <h1>Sign in</h1>
    <p class="partner-lead">Catalog partners only. Advertisers and ordinary publishers use the main site.</p>
    <form method="post" action="{{ \App\Support\PartnerDashboard::route('login.store') }}" class="partner-form">
        @csrf
        <div class="stack">
            <label>Email
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </label>
            <label>Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <label><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            <button class="partner-btn" type="submit">Sign in</button>
        </div>
    </form>
    @if (!empty($googleEnabled))
        <p class="partner-or">or</p>
        <a class="partner-google" href="{{ url('/auth/google') }}">Continue with Google</a>
    @endif
    <p class="partner-follow-link"><a href="{{ \App\Support\PartnerDashboard::route('password.request') }}">Forgot password?</a></p>
    <p><a href="{{ $mainSiteUrl }}">Not a catalog partner? Sign in on the main site</a></p>
@endsection
