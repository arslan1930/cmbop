@extends('partner.layouts.guest')
@section('title', 'Forgot password')
@section('content')
    <h1>Forgot password</h1>
    <form method="post" action="{{ \App\Support\PartnerDashboard::route('password.email') }}" class="partner-form">
        @csrf
        <div class="stack">
            <label>Email
                <input type="email" name="email" value="{{ old('email') }}" required>
            </label>
            <button class="partner-btn" type="submit">Send reset link</button>
        </div>
    </form>
    <p class="partner-follow-link"><a href="{{ \App\Support\PartnerDashboard::route('login') }}">Back to sign in</a></p>
@endsection
