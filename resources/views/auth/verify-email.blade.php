@extends('layouts.app')

@section('title', 'Verify Email - SEOLinkBuildings')

@section('content')
<div class="container py-5 mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-body text-center p-5">
                    <i class="fa-solid fa-envelope-circle-check fa-4x text-primary mb-4"></i>
                    
                    <h3 class="mb-3">Verify Your Email Address</h3>
                    
                    @if (session('status') == 'verification-link-sent')
                        <div class="alert alert-success">
                            A new verification link has been sent to your email address.
                        </div>
                    @endif
                    
                    <p class="text-muted mb-4">
                        Before proceeding, please check your email for a verification link.
                    </p>
                    
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
                </div>
            </div>
        </div>
    </div>
</div>
@endsection