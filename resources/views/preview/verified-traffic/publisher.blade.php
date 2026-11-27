@extends('preview.verified-traffic.layout')

@section('title', 'Publisher site — Verified traffic preview')

@section('content')
<div class="vt-site-head">
    <div>
        <h1>Edit site · {{ $site['name'] }}</h1>
        <p>{{ $site['url'] }} · demo listing. Traffic field already exists on the add-site wizard; this card would sit beside it.</p>
    </div>
    @if($state === 'connected' || $state === 'all')
        @include('preview.verified-traffic.partials.badge')
    @endif
</div>

@if($state === 'all' || $state === 'connect')
    @if($state === 'all')
        <div class="vt-state-label">State A — not connected</div>
    @endif
    <div class="vt-state-block">
        @include('preview.verified-traffic.partials.connect-card')
    </div>
@endif

@if($state === 'all' || $state === 'picker')
    @if($state === 'all')
        <div class="vt-state-label mt-4">State B — property picker after OAuth</div>
    @endif
    <div class="vt-state-block">
        @include('preview.verified-traffic.partials.picker')
    </div>
@endif

@if($state === 'all' || $state === 'connected')
    @if($state === 'all')
        <div class="vt-state-label mt-4">State C — connected, weekly sync</div>
    @endif
    <div class="vt-state-block">
        @include('preview.verified-traffic.partials.connected')
    </div>
@endif
@endsection
