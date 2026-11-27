@extends('preview.verified-traffic.layout')

@section('title', 'Verified traffic — draft preview')

@section('content')
<div class="vt-site-head">
    <div>
        <h1>Verified traffic (draft)</h1>
        <p>Click-through mockups in the live shell CSS. No Google OAuth, no database writes. Owner approval required before a build.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="vt-card h-100">
            <div class="vt-card__body">
                <span class="vt-card__kicker">Publisher</span>
                <h2 class="h5">My Sites</h2>
                <p class="text-muted small">Connect card, property picker, connected state with last sync.</p>
                <a class="btn btn-sm btn-primary" href="{{ route('preview.verified-traffic.publisher') }}">Open publisher mockups</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="vt-card h-100">
            <div class="vt-card__body">
                <span class="vt-card__kicker">Advertiser</span>
                <h2 class="h5">Catalog</h2>
                <p class="text-muted small">Verified badge vs manual traffic. Advertiser price only — no publisher markup.</p>
                <a class="btn btn-sm btn-primary" href="{{ route('preview.verified-traffic.catalog') }}">Open catalog mockups</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="vt-card h-100">
            <div class="vt-card__body">
                <span class="vt-card__kicker">Admin</span>
                <h2 class="h5">Site edit</h2>
                <p class="text-muted small">Source, last sync, admin override. Distinct from Ahrefs/Moz manual lock.</p>
                <a class="btn btn-sm btn-primary" href="{{ route('preview.verified-traffic.admin') }}">Open admin mockup</a>
            </div>
        </div>
    </div>
</div>
@endsection
