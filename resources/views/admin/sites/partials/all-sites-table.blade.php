@if(!empty($allSitesMode) && $allSites)
<div class="card shadow-sm border-0 mb-3 admin-table-fit" data-all-sites="1">
    <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>All sites</span>
        <span class="d-flex flex-wrap align-items-center gap-2">
            <span class="small text-muted">{{ $allSites->total() }} matching</span>
            <a class="btn btn-sm btn-outline-dark" href="{{ $sitesExportUrl ?? staff_route('sites.export') }}">CSV</a>
        </span>
    </div>
    @if(!empty($sitesExportLimited))
        <div class="px-3 py-2 small text-muted border-bottom">CSV includes the first 5,000 matching sites.</div>
    @endif
    @include('admin.sites.partials.list-filters', ['mode' => 'all'])
    <div class="px-3 py-2 border-bottom d-flex flex-wrap gap-2 align-items-center" data-staff-bulk-bar="all">
        @if(auth()->user()?->isAdmin())
            <button type="button" class="btn btn-sm btn-outline-success" data-staff-bulk="verify">Verify</button>
        @endif
        @if(auth()->user()?->canActivateSites())
            <button type="button" class="btn btn-sm btn-outline-primary" data-staff-bulk="activate">Activate</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-staff-bulk="deactivate">Deactivate</button>
        @endif
        <button type="button" class="btn btn-sm btn-outline-danger" data-staff-bulk="reject">Reject</button>
        @if(auth()->user()?->isAdmin())
            <button type="button" class="btn btn-sm btn-outline-dark" data-staff-bulk="archive">Archive</button>
        @endif
        <span class="small text-muted" data-staff-bulk-count>0 selected</span>
        <label class="form-check small mb-0">
            <input class="form-check-input" type="checkbox" data-staff-bulk-all-matching>
            All matching (<span data-staff-bulk-match-total>{{ $allSites->total() }}</span>)
        </label>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 staff-queue-table">
            <thead class="table-light">
                <tr>
                    <th class="admin-num-col"><input type="checkbox" data-staff-bulk-all="all" aria-label="Select all sites on this page"></th>
                    <th class="admin-num-col d-none d-md-table-cell">#</th>
                    <th>Site</th>
                    <th>Publisher</th>
                    <th class="admin-narrow-col d-none d-md-table-cell">DA / DR</th>
                    <th class="d-none d-lg-table-cell">Markets</th>
                    <th class="admin-narrow-col d-none d-lg-table-cell">Tag</th>
                    <th class="admin-narrow-col d-none d-md-table-cell">Traffic</th>
                    <th class="admin-narrow-col">Price</th>
                    <th class="admin-narrow-col">Listed</th>
                    <th class="admin-actions-col">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($allSites as $index => $site)
                @php
                    $openUrl = staff_route('sites.index', array_filter(
                        [
                            'publisher' => $site->publisher_id,
                            'site' => $site->id,
                            'all' => 1,
                        ] + ($listQuery ?? []),
                        static fn ($value) => $value !== null && $value !== ''
                    ));
                    $isMarketingEditor = (bool) (auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin());
                    $hasOrders = $site->orderItemsCount() > 0;
                    $canDeleteRow = ! $site->isArchived()
                        && ! $hasOrders
                        && ! $site->verified
                        && ! $site->active
                        && (auth()->user()?->isAdmin() || $isMarketingEditor);
                    $canArchiveRow = (bool) auth()->user()?->isAdmin()
                        && ! $site->isArchived()
                        && ! $hasOrders
                        && ($site->verified || $site->active);
                @endphp
                <tr data-review-name="{{ $site->site_name }}"
                    data-review-url="{{ $site->site_url }}"
                    data-review-metrics="{{ $site->da ?? '—' }} / {{ $site->dr ?? '—' }}">
                    <td><input type="checkbox" data-staff-bulk-id="{{ $site->id }}" aria-label="Select {{ $site->site_name ?: $site->domain }}"></td>
                    <td class="d-none d-md-table-cell">{{ $allSites->firstItem() + $index }}</td>
                    <td>@include('admin.sites.partials.queue-site-cell')</td>
                    <td class="small">
                        <div>{{ $site->publisher?->name ?? 'Unknown' }}</div>
                        <div class="text-muted">{{ $site->publisher?->email }}</div>
                        @if($site->publisher?->inCatalogHideMode())
                            <span class="badge text-bg-dark">Copy-strike hide</span>
                        @endif
                    </td>
                    <td class="small d-none d-md-table-cell">{{ $site->da ?? '—' }} / {{ $site->dr ?? '—' }}</td>
                    <td class="small d-none d-lg-table-cell">@include('admin.sites.partials.row-markets')</td>
                    <td class="small d-none d-lg-table-cell">{{ $site->tagLabel('No tags') }}</td>
                    <td class="d-none d-md-table-cell">{{ number_format((int) $site->traffic) }}</td>
                    <td>@include('admin.sites.partials.row-price')</td>
                    <td class="small">@include('admin.sites.partials.listed-age')</td>
                    <td class="staff-queue-actions">
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-sm btn-outline-dark" data-staff-review="{{ $site->id }}">Review</button>
                            <a href="{{ $openUrl }}" class="btn btn-sm btn-outline-secondary">Open</a>
                            <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">{{ $isMarketingEditor && $site->isLockedForMarketingEdits() && ! $site->marketingCanEditDescription() ? 'View' : 'Edit' }}</a>
                            @if(auth()->user()?->isAdmin() && ! $site->verified && ! $site->isArchived())
                                <button type="button"
                                        class="btn btn-sm btn-outline-success toggle-verify"
                                        data-id="{{ $site->id }}"
                                        data-status="1"
                                        data-name="{{ $site->site_name }}"
                                        @if($site->hasDetailsComplete()) data-publisher-reviewing="1" @endif>Verify</button>
                            @endif
                            @include('partials.staff-site-activate-button', ['site' => $site])
                            @if($canDeleteRow)
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger delete-site"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $site->site_name }}">Reject</button>
                            @elseif($canArchiveRow)
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger delete-site"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $site->site_name }}"
                                        data-archive="1">Archive</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">
                        @if(($publisherSearch ?? '') !== '' || count($listQuery ?? []) > 0)
                            Nothing matches these filters.
                            <a href="{{ staff_route('sites.index', ['all' => 1]) }}">Clear filters</a>
                        @else
                            No sites yet.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.sites.partials.pager', ['paginator' => $allSites, 'selectId' => 'allSitesPerPage'])
</div>
@endif
