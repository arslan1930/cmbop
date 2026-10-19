@extends('layouts.app')

@section('title', __('messages.publisher_onboard_password_title'))

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 py-5">
            <div class="card p-4 shadow rounded-3">
                <h1 class="h3 text-center mb-2">{{ __('messages.publisher_onboard_password_title') }}</h1>
                <p class="text-muted text-center small mb-4">{{ __('messages.publisher_onboard_password_lead', ['email' => $user->email]) }}</p>

                <form method="POST" action="{{ $formAction }}" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="password">{{ __('messages.publisher_onboard_password_label') }}</label>
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" required minlength="8">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">{{ __('messages.publisher_onboard_password_confirm') }}</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control @error('password_confirmation') is-invalid @enderror" autocomplete="new-password" required>
                        @error('password_confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" name="terms" id="terms" value="1" required>
                        <label class="form-check-label" for="terms">{{ __('messages.publisher_onboard_terms') }}</label>
                        @error('terms')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100">{{ __('messages.publisher_onboard_password_submit') }}</button>
                </form>

                <p class="text-center small text-muted mt-3 mb-0">{{ __('messages.publisher_onboard_google_alt') }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
