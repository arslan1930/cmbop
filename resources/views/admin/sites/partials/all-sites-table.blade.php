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
            Apply to all <span data-staff-bulk-match-total>{{ $allSites->total() }}</span> filtered sites
        </label>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 staff-queue-table">
            <thead class="table-light">
                <tr>
                    <th class="admin-num-col"><input type="checkbox" data-staff-bulk-all="all" aria-label="Select all sites on this page"></th>
                    <th class="admin-num-col d-none d-md-table-cell">#</th>
                    <th class="staff-queue-site-col">Site</th>
                    <th class="staff-queue-publisher-col">Publisher</th>
                    <th class="admin-narrow-col d-none d-md-table-cell">DA / DR</th>
                    <th class="staff-queue-markets-col d-none d-lg-table-cell">Markets</th>
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
                        ] + ($sitesReturnQuery ?? $listQuery ?? []),
                        static fn ($value) => $value !== null && $value !== ''
                    ));
                    $isMarketingEditor = (bool) (auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin());
                @endphp
                <tr data-review-name="{{ $site->site_name }}"
                    data-review-url="{{ $site->site_url }}"
                    data-review-metrics="{{ $site->da ?? '—' }} / {{ $site->dr ?? '—' }}">
                    <td><input type="checkbox"
                        data-staff-bulk-id="{{ $site->id }}"
                        data-verified="{{ $site->verified ? '1' : '0' }}"
                        data-active="{{ $site->active ? '1' : '0' }}"
                        data-below-bar="{{ $site->hasGoodMetrics() ? '0' : '1' }}"
                        data-can-activate="{{ $site->staffGoLiveBlockReason((bool) (auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin())) === null ? '1' : '0' }}"
                        @if($site->wasAddedByPublisher()) data-publisher-added="1" @endif
                        @if($site->isBulkRequestDraft()) data-bulk-draft="1" @endif
                        aria-label="Select {{ $site->site_name ?: $site->domain }}"></td>
                    <td class="d-none d-md-table-cell">{{ $allSites->firstItem() + $index }}</td>
                    <td class="staff-queue-site-col">@include('admin.sites.partials.queue-site-cell', ['compactSiteCell' => true])</td>
                    <td class="small staff-queue-publisher-col">
                        <div class="staff-queue-publisher" title="{{ $site->publisher?->name ?? 'Unknown' }}">{{ $site->publisher?->name ?? 'Unknown' }}</div>
                        <div class="text-muted staff-queue-publisher" title="{{ $site->publisher?->email }}">{{ $site->publisher?->email }}</div>
                        @if($site->publisher?->inCatalogHideMode())
                            <span class="badge text-bg-dark">Copy-strike hide</span>
                        @endif
                    </td>
                    <td class="small d-none d-md-table-cell">@include('admin.sites.partials.row-da-dr')</td>
                    <td class="small staff-queue-markets-col d-none d-lg-table-cell">@include('admin.sites.partials.row-markets')</td>
                    <td class="small d-none d-lg-table-cell">
                        @if($site->tagValue() === null)
                            <a href="{{ staff_route('sites.edit', $site->id) }}#site_tag" class="badge text-bg-warning text-dark text-decoration-none" title="Choose a tag. This does not block going live.">No tags</a>
                        @else
                            {{ $site->tagLabel() }}
                        @endif
                    </td>
                    <td class="small d-none d-md-table-cell">@include('admin.sites.partials.row-traffic')</td>
                    <td>@include('admin.sites.partials.row-price')</td>
                    <td class="small">@include('admin.sites.partials.listed-age')</td>
                    <td class="staff-queue-actions">
                        <div class="d-flex flex-wrap gap-1">
                            @php
                                $editOrView = $isMarketingEditor && $site->isLockedForMarketingEdits() && ! $site->marketingCanEditDescription() ? 'View' : 'Edit';
                            @endphp
                            <button type="button" class="btn btn-sm btn-outline-dark staff-action-icon-btn" data-staff-review="{{ $site->id }}" title="Review" aria-label="Review">
                                <i class="fa fa-search" aria-hidden="true"></i>
                            </button>
                            <a href="{{ $openUrl }}" class="btn btn-sm btn-outline-secondary staff-action-icon-btn" title="Open" aria-label="Open">
                                <i class="fa fa-folder-open" aria-hidden="true"></i>
                            </a>
                            <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary staff-action-icon-btn" title="{{ $editOrView }}" aria-label="{{ $editOrView }}">
                                <i class="fa {{ $editOrView === 'View' ? 'fa-eye' : 'fa-edit' }}" aria-hidden="true"></i>
                            </a>
                            @if(auth()->user()?->isAdmin() && ! $site->verified && ! $site->isArchived())
                                <button type="button"
                                        class="btn btn-sm btn-outline-success toggle-verify staff-action-icon-btn"
                                        data-id="{{ $site->id }}"
                                        data-status="1"
                                        data-name="{{ $site->site_name }}"
                                        title="Verify"
                                        aria-label="Verify"
                                        @if($site->hasDetailsComplete()) data-publisher-reviewing="1" @endif>
                                    <i class="fa fa-check" aria-hidden="true"></i>
                                </button>
                            @endif
                            @include('partials.staff-site-activate-button', ['site' => $site, 'iconOnly' => true])
                            @include('admin.sites.partials.row-reject-archive-actions', ['site' => $site])
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">
                        @if(($publisherSearch ?? '') !== '' || count($listQuery ?? []) > 0)
                            Nothing matches these filters.
                            <a href="{{ staff_route('sites.index') }}">Clear filters</a>
                        @else
                            No sites yet.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.sites.partials.pager', ['paginator' => $allSites, 'selectId' => 'allSitesPerPage', 'modeQuery' => $pagerModeQuery ?? []])
</div>
@endif
