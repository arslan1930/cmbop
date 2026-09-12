@extends('layouts.app')

@section('title', __('messages.meta_marketplace_title'))
@section('description', __('messages.meta_marketplace_description'))
@section('canonical', localized_url('marketplace'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.marketplace_kicker'),
    'title' => __('messages.marketplace_title'),
    'subtitle' => __('messages.marketplace_subtitle'),
])

<div class="container py-5">
    <div class="row g-4 mb-5">
        @foreach(range(1, 3) as $i)
            <div class="col-md-4">
                <div class="h-100 p-4 rounded-4 bg-white border">
                    <h2 class="h5" style="color:#1a585e;">{{ __('messages.marketplace_point_'.$i.'_title') }}</h2>
                    <p class="text-muted mb-0">{{ __('messages.marketplace_point_'.$i.'_body') }}</p>
                </div>
            </div>
        @endforeach
    </div>

    @if(isset($teasers) && $teasers->isNotEmpty())
        <div class="mb-4 text-center">
            <h2 class="h4 mb-1" style="color:#1a585e;">{{ __('messages.marketplace_teaser_title') }}</h2>
            <p class="text-muted mb-0">{{ __('messages.marketplace_teaser_subtitle') }}</p>
        </div>
        <div class="table-responsive mb-4 rounded-4 border bg-white">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Site</th>
                        <th>Country</th>
                        <th>Language</th>
                        <th>DR</th>
                        <th>DA</th>
                        <th>From</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($teasers as $site)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $site['name'] }}</div>
                                <div class="small text-muted">{{ $site['domain_masked'] }}</div>
                            </td>
                            <td>{{ strtoupper((string) ($site['country'] ?: '—')) }}</td>
                            <td>{{ strtoupper((string) ($site['language'] ?: '—')) }}</td>
                            <td>{{ $site['dr'] ?? '—' }}</td>
                            <td>{{ $site['da'] ?? '—' }}</td>
                            <td class="fw-semibold" style="color:#1a585e;">€{{ number_format((float) $site['price'], 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="text-center mt-4 d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
        <a href="{{ localized_url('how-it-works') }}" class="btn btn-outline-secondary btn-lg px-4">{{ __('messages.nav_how_it_works') }}</a>
    </div>
    <p class="text-center text-muted small mt-3 mb-0">{{ __('messages.marketplace_catalog_note') }}</p>

    @if(!empty($countryLanders))
        <nav class="mt-5 pt-4 border-top text-center" aria-label="Country landers">
            <h2 class="h6 text-muted mb-3">Guest posts by market</h2>
            <ul class="list-inline mb-0">
                @foreach($countryLanders as $landerLink)
                    <li class="list-inline-item me-3 mb-2">
                        <a href="{{ $landerLink['url'] }}">{{ $landerLink['market'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
</div>
@endsection
