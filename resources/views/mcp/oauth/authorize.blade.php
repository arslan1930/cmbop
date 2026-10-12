@extends('layouts.app')

@section('title', 'Connect an AI assistant')

@section('content')
<link href="{{ asset('assets/css/auth-pages.css') }}?v={{ @filemtime(public_path('assets/css/auth-pages.css')) ?: '1' }}" rel="stylesheet">

<div class="auth-page">
    <div class="container auth-shell">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="auth-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <img src="{{ asset('assets/img/logo.svg') }}?v={{ @filemtime(public_path('assets/img/logo.svg')) ?: '1' }}"
                             alt="SEOLinkBuildings" width="200" height="44">
                    </div>
                    <h1 class="h4 text-center mb-2">Connect {{ $client->name }}</h1>
                    <p class="text-muted text-center small mb-4">
                        This assistant will search the live SEOLinkBuildings catalog, save favorites or cart items,
                        and read your orders and advertiser wallet. It cannot place orders or see publisher payout details.
                    </p>

                    <div class="border rounded p-3 mb-4 bg-light">
                        <div class="small text-muted">Signed in as</div>
                        <div class="fw-semibold">{{ $user->name }}</div>
                        <div class="small">{{ $user->email }}</div>
                    </div>

                    <ul class="small text-muted mb-4">
                        <li>Search live publisher listings (EUR prices, hide-mode respected)</li>
                        <li>Save sites to your favorites or cart</li>
                        <li>Read your own orders and advertiser wallet balance</li>
                    </ul>

                    <form method="POST" action="{{ route('mcp.oauth.approve') }}" class="d-flex gap-2">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
                        <input type="hidden" name="state" value="{{ $state }}">
                        <input type="hidden" name="code_challenge" value="{{ $codeChallenge }}">
                        <input type="hidden" name="code_challenge_method" value="{{ $codeChallengeMethod }}">
                        <input type="hidden" name="scope" value="{{ $scope }}">
                        <button type="submit" name="decision" value="deny" class="btn btn-outline-secondary flex-grow-1">Deny</button>
                        <button type="submit" name="decision" value="approve" class="btn btn-primary flex-grow-1">Allow</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
