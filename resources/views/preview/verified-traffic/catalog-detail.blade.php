@extends('preview.verified-traffic.layout')

@section('title', 'Site detail — Verified traffic preview')

@section('content')
<div class="vt-site-head">
    <div>
        <h1>Listing detail</h1>
        <p>Same number advertisers already see as monthly visits. The badge is the only extra signal. Last-sync stays on the publisher and admin sides.</p>
    </div>
</div>

<div class="vt-detail">
    <div class="vt-detail__panel">
        <h2 class="h5 mb-1">{{ $verified['name'] }}</h2>
        <div class="vt-host mb-3">{{ $verified['host'] }}</div>
        <dl class="vt-dl">
            <dt>Traffic</dt>
            <dd>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <strong>{{ number_format($verified['traffic']) }}</strong>
                    <span class="text-muted">monthly visits</span>
                    @include('preview.verified-traffic.partials.badge', ['via' => $verified['via']])
                </div>
            </dd>
            <dt>DR / DA</dt>
            <dd>{{ $verified['dr'] }} / {{ $verified['da'] }}</dd>
            <dt>Category</dt>
            <dd>{{ $verified['category'] }}</dd>
            <dt>Country</dt>
            <dd>{{ $verified['country'] }}</dd>
            <dt>Guest post</dt>
            <dd class="vt-price">{{ $verified['price'] }}</dd>
        </dl>
        <p class="vt-fineprint mb-0">Tooltip copy for the badge: “Monthly visits confirmed from this publisher’s Google Analytics. SEOLinkBuildings does not show page-level reports.” Dates of the last Google pull are not shown here.</p>
    </div>

    <div class="vt-detail__panel">
        <h2 class="h5 mb-1">{{ $manual['name'] }}</h2>
        <div class="vt-host mb-3">{{ $manual['host'] }}</div>
        <dl class="vt-dl">
            <dt>Traffic</dt>
            <dd>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <strong>{{ number_format($manual['traffic']) }}</strong>
                    <span class="text-muted">monthly visits</span>
                    <span class="vt-badge vt-badge--ghost">Manual</span>
                </div>
            </dd>
            <dt>DR / DA</dt>
            <dd>{{ $manual['dr'] }} / {{ $manual['da'] }}</dd>
            <dt>Category</dt>
            <dd>{{ $manual['category'] }}</dd>
            <dt>Country</dt>
            <dd>{{ $manual['country'] }}</dd>
            <dt>Guest post</dt>
            <dd class="vt-price">{{ $manual['price'] }}</dd>
        </dl>
        <p class="vt-fineprint mb-0">Plan option: hide the grey Manual chip on catalog and only show a badge when verified (recommended). This mockup shows both so the difference is obvious in review.</p>
    </div>
</div>
@endsection
