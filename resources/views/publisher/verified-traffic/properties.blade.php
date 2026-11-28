@extends('publisher.layouts.app')

@section('title', __('messages.verified_traffic_title'))

@push('page-styles')
    <link href="{{ asset('assets/css/verified-traffic.css') }}?v={{ @filemtime(public_path('assets/css/verified-traffic.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')
@php
    $connection = $connection ?? null;
    $connected = $connection && $connection->status === \App\Support\VerifiedTraffic::STATUS_CONNECTED && $connection->hasProperty();
    $pending = $connection && ! $connection->hasProperty();
@endphp
<div class="vt-page">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $site->site_name }}</h1>
            <p class="text-muted mb-0">{{ $site->domain ?: $site->site_url }}</p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('publisher.websites') }}">{{ __('messages.verified_traffic_back') }}</a>
    </div>

    @if($connected)
        <section class="vt-card mb-3" id="vt-connected">
            <div class="vt-card__body">
                <span class="vt-card__kicker">{{ __('messages.verified_traffic_connected_kicker') }}</span>
                <h2 class="vt-card__title">{{ __('messages.verified_traffic_connected_title') }}</h2>
                <p class="vt-card__lead">
                    {{ $connection->google_account_email }}
                    @if($connection->ga4_property_name)
                        · {{ $connection->ga4_property_name }}
                    @endif
                    @if($connection->gsc_site_url)
                        · {{ $connection->gsc_site_url }}
                    @endif
                </p>
                @include('advertiser.partials.verified-traffic-badge', ['site' => $site])
                <div class="vt-connected-grid">
                    <div class="vt-stat">
                        <span class="vt-stat__label">{{ __('messages.verified_traffic_listing') }}</span>
                        <span class="vt-stat__value">{{ number_format((int) $site->traffic) }}</span>
                    </div>
                    <div class="vt-stat">
                        <span class="vt-stat__label">GA4</span>
                        <span class="vt-stat__value">{{ $site->traffic_ga4 !== null ? number_format((int) $site->traffic_ga4) : '—' }}</span>
                    </div>
                    <div class="vt-stat">
                        <span class="vt-stat__label">Search Console</span>
                        <span class="vt-stat__value">{{ $site->traffic_gsc_clicks !== null ? number_format((int) $site->traffic_gsc_clicks) : '—' }}</span>
                    </div>
                    <div class="vt-stat">
                        <span class="vt-stat__label">{{ __('messages.verified_traffic_last_sync') }}</span>
                        <span class="vt-stat__value" style="font-size:16px">
                            {{ $connection->last_synced_at ? $connection->last_synced_at->diffForHumans() : __('messages.verified_traffic_sync_pending') }}
                        </span>
                    </div>
                </div>
                <div class="vt-actions">
                    <a class="btn btn-outline-secondary" href="{{ route('publisher.sites.google.properties', [$site, 'change' => 1]) }}">{{ __('messages.verified_traffic_picker_title') }}</a>
                    <form method="POST" action="{{ route('publisher.sites.google.disconnect', $site) }}"
                          data-slb-confirm="{{ __('messages.verified_traffic_disconnect_confirm') }}"
                          data-slb-confirm-title="{{ __('messages.verified_traffic_disconnect') }}"
                          data-slb-confirm-text="{{ __('messages.verified_traffic_disconnect') }}"
                          data-slb-confirm-danger="1">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger">{{ __('messages.verified_traffic_disconnect') }}</button>
                    </form>
                </div>
            </div>
        </section>
    @endif

    @if(! $oauthConfigured)
        <div class="alert alert-secondary">{{ __('messages.verified_traffic_unavailable') }}</div>
    @elseif(! $connection)
        <section class="vt-card" id="vt-connect">
            <div class="vt-card__body">
                <span class="vt-card__kicker">Google</span>
                <h2 class="vt-card__title">{{ __('messages.verified_traffic_connect') }}</h2>
                <p class="vt-card__lead">{{ __('messages.verified_traffic_connect_lead') }}</p>
                <a class="vt-google-btn" href="{{ route('publisher.sites.google.redirect', $site) }}">
                    @include('publisher.verified-traffic.partials.google-icon')
                    {{ __('messages.verified_traffic_connect') }}
                </a>
                <p class="vt-fineprint mb-0">{{ __('messages.verified_traffic_connect_scopes') }}</p>
            </div>
        </section>
    @elseif($showPicker ?? true)
        <section class="vt-card" id="vt-picker">
            <div class="vt-card__body">
                <span class="vt-card__kicker">{{ __('messages.verified_traffic_picker_kicker') }}</span>
                <h2 class="vt-card__title">{{ __('messages.verified_traffic_picker_title') }}</h2>
                @if($listError)
                    <div class="alert alert-warning">{{ $listError }}</div>
                @endif
                <form method="POST" action="{{ route('publisher.sites.google.properties.save', $site) }}">
                    @csrf
                    <h3 class="form-section-title">Google Analytics 4</h3>
                    <label class="vt-property">
                        <input type="radio" name="ga4_property_id" value="" {{ empty($connection->ga4_property_id) ? 'checked' : '' }}>
                        <span>{{ __('messages.verified_traffic_skip_ga4') }}</span>
                    </label>
                    @forelse($ga4 as $row)
                        <label class="vt-property {{ $row['match'] ? 'is-match' : 'is-off' }}">
                            <input type="radio" name="ga4_property_id" value="{{ $row['id'] }}"
                                   {{ $connection->ga4_property_id === $row['id'] ? 'checked' : '' }}
                                   {{ $row['match'] ? '' : 'disabled' }}>
                            <span>
                                <span class="vt-property__name">{{ $row['name'] }}</span>
                                <span class="vt-property__meta d-block">{{ $row['id'] }}{{ $row['host'] ? ' · '.$row['host'] : '' }}</span>
                                @if($row['match'])
                                    <span class="vt-pill mt-1">{{ __('messages.verified_traffic_domain_match') }}</span>
                                @else
                                    <span class="vt-pill vt-pill--warn mt-1">{{ __('messages.verified_traffic_domain_mismatch') }}</span>
                                @endif
                            </span>
                        </label>
                    @empty
                        <p class="text-muted small">{{ __('messages.verified_traffic_no_ga4') }}</p>
                    @endforelse

                    <h3 class="form-section-title mt-4">Search Console</h3>
                    <label class="vt-property">
                        <input type="radio" name="gsc_site_url" value="" {{ empty($connection->gsc_site_url) ? 'checked' : '' }}>
                        <span>{{ __('messages.verified_traffic_skip_gsc') }}</span>
                    </label>
                    @forelse($gsc as $row)
                        <label class="vt-property {{ $row['match'] ? 'is-match' : 'is-off' }}">
                            <input type="radio" name="gsc_site_url" value="{{ $row['id'] }}"
                                   {{ $connection->gsc_site_url === $row['id'] ? 'checked' : '' }}
                                   {{ $row['match'] ? '' : 'disabled' }}>
                            <span>
                                <span class="vt-property__name">{{ $row['name'] }}</span>
                                @if($row['match'])
                                    <span class="vt-pill mt-1">{{ __('messages.verified_traffic_domain_match') }}</span>
                                @else
                                    <span class="vt-pill vt-pill--warn mt-1">{{ __('messages.verified_traffic_domain_mismatch') }}</span>
                                @endif
                            </span>
                        </label>
                    @empty
                        <p class="text-muted small">{{ __('messages.verified_traffic_no_gsc') }}</p>
                    @endforelse

                    <div class="vt-actions">
                        <button type="submit" class="btn btn-primary">{{ __('messages.verified_traffic_save_sync') }}</button>
                    </div>
                </form>
            </div>
        </section>
    @endif
</div>
@endsection
