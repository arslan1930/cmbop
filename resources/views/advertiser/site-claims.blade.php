@extends('advertiser.layouts.app')

@section('title', 'My Claims')

@push('page-styles')
<link href="{{ asset('assets/css/advertiser-site-claims.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-site-claims.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')
@php
    $claims = $claims ?? collect();
@endphp

<div class="container-fluid claims-page">
    <div class="claims-page-header">
        <div>
            <h1 class="claims-page-title">My Claims</h1>
            <p class="claims-page-sub">
                Your ownership claims for catalog listings. We email you after each review.
            </p>
        </div>
        <a href="{{ route('advertiser.catalog') }}" class="btn btn-sm btn-primary claims-browse">
            Browse catalog
        </a>
    </div>

    <div class="claims-card">
        @if($claims->isEmpty())
            <div class="p-4">
                <x-ui.empty-state
                    icon="fa-user-check"
                    title="No ownership claims yet"
                    message="If a catalog listing is yours, claim it from the site row. Status and review notes show up here."
                    primary-label="Browse catalog"
                    :primary-url="route('advertiser.catalog')"
                />
            </div>
        @else
            <div class="table-responsive claims-desktop-only">
                <table class="table align-middle mb-0 data-table">
                    <thead class="table-light">
                        <tr>
                            <th>Website</th>
                            <th>Name match</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Reviewed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($claims as $claim)
                            @include('advertiser.partials.site-claim-row', ['claim' => $claim, 'layout' => 'row'])
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="claims-mobile-only p-3">
                @foreach($claims as $claim)
                    @include('advertiser.partials.site-claim-row', ['claim' => $claim, 'layout' => 'card'])
                @endforeach
            </div>

            @if(method_exists($claims, 'links'))
                <div class="claims-pager">{{ $claims->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
