@php
    $selectedCountry = strtolower(trim(scalar_text($selectedCountry ?? '')));
    $missingMarket = (bool) ($missingMarket ?? false);
    $healthFilter = \App\Support\CatalogHealthQueue::normalize($healthFilter ?? ($missingMarket ? \App\Support\CatalogHealthQueue::MISSING_MARKET : null));
    $liveFilter = (bool) ($liveFilter ?? false);
    $healthLabels = \App\Support\CatalogHealthQueue::LABELS;
    if (! is_iterable($sites ?? null)) {
        $sites = collect();
    }
@endphp
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width:16rem;">URL</th>
                    <th style="min-width:6rem;">Active</th>
                    <th style="min-width:8rem;">Countries</th>
                    <th style="min-width:12rem;">Categories</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sites as $site)
                    @php $site = is_array($site) ? $site : []; @endphp
                    <tr>
                        <td class="text-break">
                            @if(($href = scalar_text($site['href'] ?? '')) !== '')
                                <a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ scalar_text($site['url'] ?? $href) }}</a>
                            @elseif(($url = scalar_text($site['url'] ?? '')) !== '')
                                <span>{{ $url }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            @if(($adminUrl = scalar_text($site['admin_url'] ?? '')) !== '')
                                <div class="small mt-1">
                                    <a href="{{ $adminUrl }}" class="link-secondary">Open in admin</a>
                                </div>
                            @endif
                            @foreach(scalar_list($site['health_flags'] ?? []) as $flag)
                                <span class="badge {{ $flag === 'missing_market' ? 'text-bg-danger' : 'text-bg-warning' }} ms-1">{{ $healthLabels[$flag] ?? $flag }}</span>
                            @endforeach
                            @if(empty($site['health_flags']) && !empty($site['missing_market']))
                                <span class="badge text-bg-secondary ms-1">No country</span>
                            @endif
                        </td>
                        <td>
                            @if(!empty($site['active']))
                                <span class="badge text-bg-success">Live</span>
                            @else
                                <span class="badge text-bg-secondary">Off</span>
                            @endif
                        </td>
                        <td class="text-uppercase small">{{ ($countriesCell = scalar_text($site['countries'] ?? '')) !== '' ? $countriesCell : '—' }}</td>
                        <td class="small">{{ ($categoriesCell = scalar_text($site['categories'] ?? '')) !== '' ? $categoriesCell : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            @if($healthFilter)
                                No websites match the {{ strtolower($healthLabels[$healthFilter] ?? 'health') }} queue.
                            @elseif($liveFilter && $selectedCountry !== '')
                                No live websites found for country <span class="text-uppercase">{{ $selectedCountry }}</span>.
                            @elseif($liveFilter)
                                No live websites.
                            @elseif($selectedCountry !== '')
                                No websites found for country <span class="text-uppercase">{{ $selectedCountry }}</span>.
                            @else
                                No websites found.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if(is_object($sites) && method_exists($sites, 'hasPages') && $sites->hasPages())
    <div class="d-flex justify-content-center mt-3" data-records-pagination>
        @php
            try {
                echo $sites->links();
            } catch (\Throwable $e) {
                report($e);
            }
        @endphp
    </div>
@endif
