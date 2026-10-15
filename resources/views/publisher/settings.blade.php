@extends('publisher.layouts.app')

@section('title', 'Settings')

@section('content')
@php
    $user = auth()->user();
    $payoutProfile = $payoutProfile ?? $user->payoutProfile();
    $accountCurrency = $accountCurrency ?? 'EUR';
    $currencyLabels = ['EUR' => '€ EUR', 'GBP' => '£ GBP', 'USD' => '$ USD'];
    $currencyLabel = $currencyLabels[$accountCurrency] ?? $accountCurrency;
    $minWithdrawalAmount = (float) ($minWithdrawalAmount ?? 20);
    $method = $payoutProfile['preferred_method'] ?? null;
    $methodLabel = match ($method) {
        'bank' => 'Bank transfer',
        'paypal' => 'PayPal',
        'wise' => 'Wise',
        'crypto' => 'Cryptocurrency',
        default => 'Not set',
    };
@endphp
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h1 class="h3 mb-1 fw-semibold">Settings</h1>
            <p class="text-muted mb-0">Profile, email preferences, payout details, and account currency.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Profile</h2>
                    <p class="mb-1"><strong>{{ $user->name }}</strong></p>
                    <p class="text-muted small mb-3">{{ $user->email }}</p>
                    <a href="{{ route('profile') }}" class="btn btn-primary btn-sm">Edit profile</a>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Account currency</h2>
                    <p class="mb-1 fw-semibold">{{ $currencyLabel }}</p>
                    <p class="small text-muted mb-0">
                        Saved on your account. Catalog prices and wallet labels follow this.
                        It is not a guest header switch — contact support if you need it changed.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Payout details</h2>
                    <p class="mb-1">Method: <strong>{{ $methodLabel }}</strong></p>
                    <p class="small text-muted mb-3">
                        Minimum withdrawal is {{ format_money_amount($minWithdrawalAmount, $accountCurrency) }}
                        in this wallet currency. Confirm payout fields twice on Withdraw.
                    </p>
                    <a href="{{ route('publisher.withdraw') }}" class="btn btn-primary btn-sm">Open withdraw</a>
                    <a href="{{ route('publisher.billing.index') }}" class="btn btn-outline-secondary btn-sm">Payout docs</a>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Email preferences</h2>
                    <p class="small text-muted mb-3">Choose which emails you receive. Security alerts stay on.</p>
                    <a href="{{ route('profile.notifications') }}" class="btn btn-outline-secondary btn-sm">Email preferences</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
