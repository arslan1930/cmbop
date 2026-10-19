@extends('partner.layouts.guest')
@section('title', 'Set your password')
@section('content')
    <h1>Set your password</h1>
    <p class="partner-lead">Choose a password for {{ $user->email }}. You will then open the partner dashboard.</p>
    <form method="post" action="{{ $formAction }}" class="partner-form">
        @csrf
        <div class="stack">
            <label>Password (8+ characters)
                <input type="password" name="password" required minlength="8" autocomplete="new-password">
            </label>
            <label>Confirm password
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>
            <label><input type="checkbox" name="terms" value="1" required> I agree to the Terms of Service</label>
            <button class="partner-btn" type="submit">Save password and continue</button>
        </div>
    </form>
@endsection
