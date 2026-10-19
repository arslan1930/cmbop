@extends('partner.layouts.guest')
@section('title', 'Reset password')
@section('content')
    <h1>Choose a new password</h1>
    <form method="post" action="{{ \App\Support\PartnerDashboard::route('password.update') }}" class="partner-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="stack">
            <label>Email
                <input type="email" name="email" value="{{ old('email', $email) }}" required>
            </label>
            <label>New password (12+ characters)
                <input type="password" name="password" required>
            </label>
            <label>Confirm password
                <input type="password" name="password_confirmation" required>
            </label>
            <button class="partner-btn" type="submit">Save password</button>
        </div>
    </form>
@endsection
