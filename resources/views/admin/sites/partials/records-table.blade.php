@php
    $selectedCountry = $selectedCountry ?? '';
    $missingMarket = (bool) ($missingMarket ?? false);
    $healthFilter = $healthFilter ?? ($missingMarket ? \App\Support\CatalogHealthQueue::MISSING_MARKET : null);
    $healthLabels = \App\Support\CatalogHealthQueue::LABELS;
@endphp
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="recordsSheetTable">
            <thead class="table-light">
                <tr>
                    <th style="width:2.25rem;">
                        <input type="checkbox" class="form-check-input" id="recordsSelectAll" aria-label="Select all on this page">
                    </th>
                    <th style="min-width:16rem;">Site</th>
                    <th style="min-width:8rem;">Countries</th>
                    <th style="min-width:12rem;">Categories</th>
                    <th style="min-width:8rem;">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($sites as $site)
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="form-check-input js-records-row"
                                   value="{{ $site['id'] }}"
                                   data-verified="{{ !empty($site['verified']) ? '1' : '0' }}"
                                   data-can-activate="{{ !empty($site['can_activate']) ? '1' : '0' }}"
                                   aria-label="Select {{ $site['site_name'] }}">
                        </td>
                        <td class="text-break">
                            <div class="fw-semibold">{{ $site['site_name'] }}</div>
                            @if($site['url'] !== '')
                                <a href="{{ $site['url'] }}" target="_blank" rel="noopener noreferrer">{{ $site['url'] }}</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            @foreach(($site['health_flags'] ?? []) as $flag)
                                <span class="badge {{ $flag === 'missing_market' ? 'text-bg-danger' : 'text-bg-warning' }} ms-1">{{ $healthLabels[$flag] ?? $flag }}</span>
                            @endforeach
                            @if(empty($site['health_flags']) && !empty($site['missing_market']))
                                <span class="badge text-bg-secondary ms-1">No country</span>
                            @endif
                        </td>
                        <td class="text-uppercase small">{{ $site['countries'] !== '' ? $site['countries'] : '—' }}</td>
                        <td class="small">{{ $site['categories'] !== '' ? $site['categories'] : '—' }}</td>
                        <td>
                            <span class="badge {{ !empty($site['verified']) ? 'text-bg-success' : 'text-bg-warning' }}">
                                {{ !empty($site['verified']) ? 'Verified' : 'Unverified' }}
                            </span>
                            <span class="badge {{ !empty($site['active']) ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                {{ !empty($site['active']) ? 'Active' : 'Off' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="{{ $site['edit_url'] }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            @if($healthFilter)
                                No websites match the {{ strtolower($healthLabels[$healthFilter] ?? 'health') }} queue.
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

@if($sites->hasPages())
    <div class="d-flex justify-content-center mt-3" data-records-pagination>
        {{ $sites->links() }}
    </div>
@endif
