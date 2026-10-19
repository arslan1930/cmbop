@extends('partner.layouts.app')
@section('title', 'Home')
@section('content')
    <h1 class="partner-h1">Hello, {{ $partnerName }}</h1>
    <p class="partner-lead">Your live order counts. Only your own work is shown. Teqno Ltd charges no fees.</p>
    <div class="partner-tiles">
        @foreach ($labels as $key => $label)
            <a class="partner-tile" href="{{ \App\Support\PartnerDashboard::route('orders.index', ['status' => $key]) }}">
                <strong>{{ (int) ($counts[$key] ?? 0) }}</strong>
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </div>
@endsection
