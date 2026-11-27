@extends('preview.verified-traffic.layout')

@section('title', 'Catalog — Verified traffic preview')

@section('content')
<div class="vt-site-head">
    <div>
        <h1>Catalog</h1>
        <p>Advertisers see the monthly visits number plus a Verified badge when Google is connected. Manual listings stay as they are today. No last-sync date, no Google email, and the buy column is the advertiser amount only.</p>
    </div>
</div>

<div class="vt-catalog-wrap">
    <div class="table-responsive vt-desktop-table d-none d-md-block">
        <table class="table vt-catalog-table mb-0">
            <caption class="visually-hidden">Demo catalog rows comparing verified and manual traffic</caption>
            <thead>
                <tr>
                    <th scope="col">Site</th>
                    <th scope="col" class="text-center">Category</th>
                    <th scope="col" class="text-center">Traffic</th>
                    <th scope="col" class="text-center">DR</th>
                    <th scope="col" class="text-center">DA</th>
                    <th scope="col" class="text-center">Country</th>
                    <th scope="col" class="text-end">Buy</th>
                </tr>
            </thead>
            <tbody>
                @foreach([$verified, $manual] as $row)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $row['name'] }}</div>
                            <div class="vt-host">{{ $row['host'] }}</div>
                        </td>
                        <td class="text-center"><span class="category-badge">{{ $row['category'] }}</span></td>
                        <td class="text-center">
                            <div class="vt-metric-stack">
                                @include('advertiser.partials.catalog-metric', [
                                    'type' => 'traffic',
                                    'value' => $row['traffic'],
                                    'inline' => false,
                                    'site' => (object) ['id' => $row['id']],
                                ])
                                @if($row['verified_traffic'])
                                    @include('preview.verified-traffic.partials.badge', ['via' => $row['via']])
                                @else
                                    <span class="vt-badge vt-badge--ghost" title="Publisher entered this figure. Not confirmed by Google.">Manual</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-center">
                            @include('advertiser.partials.catalog-metric', ['type' => 'dr', 'value' => $row['dr'], 'inline' => false])
                        </td>
                        <td class="text-center">
                            @include('advertiser.partials.catalog-metric', ['type' => 'da', 'value' => $row['da'], 'inline' => false])
                        </td>
                        <td class="text-center">
                            <div class="catalog-country">
                                <span class="catalog-country__code">{{ $row['country_code'] }}</span>
                                <span class="catalog-country__name text-muted small">{{ $row['country'] }}</span>
                            </div>
                        </td>
                        <td class="text-end">
                            <div class="vt-price">{{ $row['price'] }}</div>
                            <a class="vt-buy mt-2" href="{{ route('preview.verified-traffic.catalog-detail') }}">Add to cart</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="vt-mobile-cards">
        @foreach([$verified, $manual] as $row)
            <article class="vt-mobile-card">
                <div class="fw-semibold">{{ $row['name'] }}</div>
                <div class="vt-host mb-2">{{ $row['host'] }}</div>
                <div class="catalog-mobile-metrics">
                    <div>
                        <span class="text-muted catalog-mobile-metrics__label">Traffic</span>
                        @include('advertiser.partials.catalog-metric', [
                            'type' => 'traffic',
                            'value' => $row['traffic'],
                            'inline' => false,
                            'site' => (object) ['id' => $row['id'] + 50],
                        ])
                        <div class="mt-1">
                            @if($row['verified_traffic'])
                                @include('preview.verified-traffic.partials.badge', ['via' => $row['via']])
                            @else
                                <span class="vt-badge vt-badge--ghost">Manual</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <span class="text-muted catalog-mobile-metrics__label">DR</span>
                        @include('advertiser.partials.catalog-metric', ['type' => 'dr', 'value' => $row['dr'], 'inline' => false])
                    </div>
                    <div>
                        <span class="text-muted catalog-mobile-metrics__label">DA</span>
                        @include('advertiser.partials.catalog-metric', ['type' => 'da', 'value' => $row['da'], 'inline' => false])
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span class="vt-price">{{ $row['price'] }}</span>
                    <a class="vt-buy" href="{{ route('preview.verified-traffic.catalog-detail') }}">Add to cart</a>
                </div>
            </article>
        @endforeach
    </div>
</div>
@endsection
