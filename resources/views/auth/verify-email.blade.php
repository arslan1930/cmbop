@extends('layouts.app')

@section('title', 'Verify Your Email | SEOLinkBuildings Marketplace')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body text-center p-5">
                    <i class="fa-solid fa-envelope-circle-check fa-4x text-primary mb-4"></i>

                    <h1 class="h3 mb-3">Verify Your Email Address</h1>

                    <p class="text-muted mb-4">
                        Before you can sign in, check your inbox for a verification link.
                        If it expired, request a new one below.
                    </p>

                    @if (! empty($authenticatedUnverified))
                        <form method="POST" action="{{ route('verification.send') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100 mb-2">
                                Resend Verification Email
                            </button>
                        </form>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-link text-muted">
                                Logout
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('verification.resend') }}" class="text-start">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="verifyEmail">Email</label>
                                <input type="email" name="email" id="verifyEmail" class="form-control @error('email') is-invalid @enderror"
                                       value="{{ old('email', $prefillEmail ?? '') }}"
                                       autocomplete="email" required
                                       aria-describedby="verifyEmailError">
                                @error('email')
                                    <div class="invalid-feedback d-block" id="verifyEmailError" role="alert">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mb-2">
                                Resend Verification Email
                            </button>
                        </form>

                        <p class="mb-0 mt-3">
                            <a href="{{ route('login', absolute: false) }}" class="text-muted">Back to sign in</a>
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
