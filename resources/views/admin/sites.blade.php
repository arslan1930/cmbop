@extends(staff_layout())

@section('content')
@php
    $publisherSearch = trim((string) ($publisherSearch ?? ''));
    $publisherSearchQuery = array_filter(['q' => $publisherSearch !== '' ? $publisherSearch : null]);
    $flatQueue = $flatQueue ?? false;
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-0 fw-bold">Sites Management</h4>
            @if(($openReviewCount ?? 0) > 0)
                <small class="text-muted">
                    <span class="badge text-bg-warning">{{ $openReviewCount }}</span>
                    site{{ $openReviewCount === 1 ? '' : 's' }} need{{ $openReviewCount === 1 ? 's' : '' }} review
                </small>
            @endif
            @if(($missingMarketCount ?? 0) > 0)
                <small class="text-muted d-block mt-1">
                    <span class="badge text-bg-danger">{{ $missingMarketCount }}</span>
                    active site{{ $missingMarketCount === 1 ? '' : 's' }} missing market country
                </small>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if(!empty($needsReviewFilterActive))
                <a href="{{ staff_route('sites.index', $publisherSearchQuery) }}" class="btn btn-sm btn-outline-dark">
                    Show all publishers
                </a>
                @if(!empty($flatQueue))
                    <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-outline-secondary">
                        By publisher
                    </a>
                @else
                    <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1, 'flat' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-outline-warning">
                        Site queue
                    </a>
                @endif
            @else
                <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-warning">
                    <i class="fa fa-bell me-1"></i> Needs review
                    @if(($openReviewCount ?? 0) > 0)
                        <span class="badge text-bg-dark ms-1">{{ $openReviewCount }}</span>
                    @endif
                </a>
                <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1, 'flat' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-outline-warning">
                    Site queue
                </a>
            @endif
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('admin.sites.records', array_filter(['missing_market' => ($missingMarketCount ?? 0) > 0 ? 1 : null])) }}"
                   class="btn btn-sm {{ ($missingMarketCount ?? 0) > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' }}">
                    <i class="fa fa-table me-1"></i> Websites records sheet
                    @if(($missingMarketCount ?? 0) > 0)
                        <span class="badge text-bg-danger ms-1">{{ $missingMarketCount }} missing</span>
                    @endif
                </a>
                <a href="{{ staff_route('site-enrichment.index') }}" class="btn btn-sm btn-outline-primary">
                    Enrichment &amp; scan failures
                </a>
            @endif
            <a href="{{ staff_route('sites.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> Add site for publisher
            </a>
        </div>
    </div>

    @if(!empty($needsReviewFilterActive) || !empty($unverifiedFilter))
        <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Needs review queue</strong>
                <span class="ms-1">
                    @if(!empty($flatQueue))
                        @if(auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin())
                            Flat list of sites waiting for Activate or delete (pending only). Admin verifies.
                        @else
                            Flat list of sites waiting for Verify, Activate, Reject, or Delete.
                        @endif
                    @elseif(auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin())
                        Publishers with new/ready sites waiting for Activate or delete (pending only). Admin verifies.
                    @else
                        Publishers with new/ready sites waiting for Verify, Activate, Reject, or Delete. Reminders stay until you decide.
                    @endif
                </span>
            </div>
            <a href="{{ staff_route('sites.index', $publisherSearchQuery) }}" class="btn btn-sm btn-outline-dark">Show all publishers</a>
        </div>
    @endif

    @if(!empty($flatQueue) && $flatQueueSites)
    <div class="card shadow-sm border-0 mb-3 admin-table-fit" data-flat-queue="1">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span>Sites needing review</span>
            <span class="small text-muted">{{ $flatQueueSites->total() }} in queue</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="admin-num-col">#</th>
                        <th>Site</th>
                        <th>Publisher</th>
                        <th class="admin-narrow-col">Traffic</th>
                        <th class="admin-narrow-col">Price</th>
                        <th class="admin-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($flatQueueSites as $index => $site)
                    @php
                        $openUrl = staff_route('sites.index', array_filter([
                            'publisher' => $site->publisher_id,
                            'site' => $site->id,
                        ]));
                    @endphp
                    <tr>
                        <td>{{ $flatQueueSites->firstItem() + $index }}</td>
                        <td>
                            <div class="fw-semibold">{{ $site->site_name ?: '—' }}</div>
                            <div class="small text-muted text-break">{{ $site->site_url }}</div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @if(! $site->hasMarketplaceCountry())
                                    <span class="badge text-bg-danger">Missing market</span>
                                @endif
                                @if(! $site->hasGoodMetrics())
                                    <span class="badge text-bg-warning text-dark">Below quality bar</span>
                                @endif
                            </div>
                        </td>
                        <td class="small">
                            <div>{{ $site->publisher?->name ?? 'Unknown' }}</div>
                            <div class="text-muted">{{ $site->publisher?->email }}</div>
                        </td>
                        <td>{{ number_format((int) $site->traffic) }}</td>
                        <td>€{{ number_format((float) $site->price, 2) }}</td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="{{ $openUrl }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">{{ $site->isLockedForMarketingEdits() ? 'View' : 'Edit' }}</a>
                                @if(auth()->user()?->canActivateSites() && $site->staffCanGoLive(auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin()))
                                    <button type="button" class="btn btn-sm btn-success js-mkt-activate" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}" data-description-english="{{ $site->descriptionLooksLikeEnglish() ? '1' : '0' }}" data-description-excerpt="{{ site_description_excerpt($site->description, 200) }}">Activate</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No sites in the review queue.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-2">
            {{ $flatQueueSites->links() }}
        </div>
    </div>
    @endif

    <!-- ================= USERS TABLE ================= -->
    <div id="usersSection" class="{{ !empty($flatQueue) ? 'd-none' : '' }}">

        <form method="GET" action="{{ staff_route('sites.index') }}" class="mb-2" style="max-width: 250px;" role="search">
            @if(!empty($needsReviewFilterActive) || !empty($unverifiedFilter))
                <input type="hidden" name="needs_review" value="1">
            @endif
            @if(!empty($flatQueue))
                <input type="hidden" name="flat" value="1">
            @endif
            <x-slb-search-field
                name="q"
                id="userSearch"
                :value="$publisherSearch"
                placeholder="Search publishers…"
                label="Search publishers"
                label-class="visually-hidden"
            />
        </form>

        <div class="card shadow-sm border-0 mb-3 admin-table-fit">
            <div class="card-header bg-white fw-semibold">
                {{ !empty($needsReviewFilterActive) || !empty($unverifiedFilter) ? 'Publishers with sites needing review' : 'Publishers' }}
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col">#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th class="admin-sites-count-col">Sites</th>
                            <th class="admin-actions-col">Action</th>
                        </tr>
                    </thead>

                    <tbody id="usersTable">
                    @forelse($users as $index => $user)
                        <tr class="user-row" data-id="{{ $user->id }}" style="height:60px;">
                            <td>{{ $users->firstItem() + $index }}</td>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td class="slb-text-break">{{ $user->email }}</td>
                            <td class="admin-sites-count-col">
                                @php
                                    $needsReviewCount = (int) ($user->needs_review_sites_count
                                        ?? $user->unverified_sites_count
                                        ?? 0);
                                    $totalSitesCount = (int) ($user->sites_count ?? 0);
                                @endphp
                                <div class="admin-sites-count-badges">
                                    @if($needsReviewCount > 0)
                                        <span class="badge rounded-pill text-bg-warning" title="Sites waiting for admin decision">
                                            {{ number_format($needsReviewCount) }} new
                                        </span>
                                    @endif
                                    <span class="badge rounded-pill bg-secondary" title="Total sites: {{ number_format($totalSitesCount) }}">
                                        {{ number_format($totalSitesCount) }} total
                                    </span>
                                </div>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary select-user"
                                        data-id="{{ $user->id }}">
                                    View Sites
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">No publishers found</td>
                        </tr>
                    @endforelse
                    </tbody>

                </table>
            </div>

            <div class="p-2">
                {{ $users->links() }}
            </div>

        </div>
    </div>

    <!-- ================= SITES FULL VIEW ================= -->
    <div id="sitesSection" class="d-none">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0 fw-bold" id="siteUserName"></h5>
                <small class="text-muted" id="siteUserEmail"></small>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="#" class="btn btn-sm btn-primary d-none" id="addSiteForPublisherBtn">
                    <i class="fa fa-plus me-1"></i> Add site
                </a>
                <button class="btn btn-sm btn-outline-secondary" id="backBtn">
                    ← Back
                </button>
            </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div style="max-width: 250px;">
                <x-slb-search-field name="site_search" id="siteSearch" placeholder="Search sites…" mode="" />
            </div>
            <div class="form-check form-check-inline m-0">
                {{-- Default OFF: needs_review=1 filters the publishers list only.
                     Pre-checking this hid activated/verified sites after Approve/Activate
                     (and again on refresh / sidebar re-entry). Staff can still toggle it. --}}
                <input class="form-check-input" type="checkbox" id="sitesNeedsReviewOnly">
                <label class="form-check-label small" for="sitesNeedsReviewOnly">Needs review only</label>
            </div>
        </div>

        <div class="card shadow-sm border-0 admin-table-fit">

            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col">#</th>
                            <th>Site Information</th>
                            <th class="admin-narrow-col">Traffic</th>
                            <th class="admin-narrow-col">Price</th>
                            <th class="admin-status-col">Status</th>
                            <th class="admin-actions-col">Actions</th>
                        </tr>
                    </thead>

                    <tbody id="sitesTable"></tbody>

                 </table>
            </div>
            <div class="p-2 d-flex flex-wrap justify-content-between align-items-center gap-2" id="sitesPager"></div>

        </div>

    </div>

</div>



<script src="{{ asset('assets/js/site-image-upload.js') }}?v={{ @filemtime(public_path('assets/js/site-image-upload.js')) ?: '1' }}"></script>
<script>
const STAFF_BASE = @json(staff_base_path());
const SITE_IMAGE_MAX_KB = {{ (int) \App\Support\SiteImageUpload::maxKilobytes() }};
const SITE_IMAGE_PHP_MAX_KB = {{ (int) \App\Support\SiteImageUpload::phpUploadMaxKilobytes() }};
const CSRF_TOKEN = @json(csrf_token());
const CAN_DELETE_ANY_SITE = @json(auth()->user()->isAdmin());
const CAN_DELETE_PENDING_SITES = @json(auth()->user()->isAdmin() || auth()->user()->isMarketing());
const CAN_VERIFY_SITES = @json(auth()->user()->isAdmin());
const CAN_TOGGLE_ACTIVE = @json(auth()->user()->canActivateSites());
const IS_MARKETING_EDITOR = @json(auth()->user()->isMarketing() && ! auth()->user()->isAdmin());
const FLAT_QUEUE = @json(! empty($flatQueue));
const QUALITY_MIN_DA = {{ (int) \App\Models\Site::GOOD_MIN_DA }};
const QUALITY_MIN_DR = {{ (int) \App\Models\Site::GOOD_MIN_DR }};
const QUALITY_MIN_TRAFFIC = {{ (int) \App\Models\Site::GOOD_MIN_TRAFFIC }};
let allSites = [];
let pendingHighlightSiteId = null;

function siteIsVerified(site) {
    return Number(site?.verified) === 1 || site?.verified === true;
}

function siteIsActive(site) {
    return Number(site?.active) === 1 || site?.active === true;
}

function siteHasOrders(site) {
    return (Number(site?.orders_count) || 0) > 0;
}

function canDeleteSiteRow(site) {
    if (site?.archived) return false;
    if (siteHasOrders(site)) return false;
    if (siteIsVerified(site) || siteIsActive(site)) return false;
    if (CAN_DELETE_ANY_SITE) return true;
    if (!CAN_DELETE_PENDING_SITES) return false;
    return true;
}

function canArchiveSiteRow(site) {
    if (!CAN_DELETE_ANY_SITE) return false;
    if (site?.archived) return false;
    if (siteHasOrders(site)) return false;
    return siteIsVerified(site) || siteIsActive(site);
}

/* ================= TOAST ================= */
function toast(msg, icon = 'success') {
    // Prefer the shared app toast — a SweetAlert toast right after the Edit Site
    // dialog closes can leave a brief black backdrop / "error" flash.
    const type = (icon === 'error' || icon === 'danger')
        ? 'error'
        : (icon === 'info' ? 'info' : (icon === 'warning' ? 'warning' : 'success'));

    showAppToast(String(msg || ''), type);
}

function releaseSwalBodyLock() {
    document.body.classList.remove('swal2-shown', 'swal2-height-auto');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    // Drop a leftover non-toast container that can leave a dark overlay flash.
    document.querySelectorAll('body > .swal2-container').forEach((el) => {
        if (el.querySelector('.swal2-toast')) return;
        if (el.classList.contains('swal2-backdrop-show') || !el.querySelector('.swal2-popup')) {
            el.remove();
        }
    });
}

/* ================= LOAD SITES ================= */
function fetchUserSites(id, page){
    const userRow = document.querySelector(`.user-row[data-id="${id}"]`);
    const addBtn = document.getElementById('addSiteForPublisherBtn');

    document.getElementById('usersSection').classList.add('d-none');
    document.getElementById('sitesSection').classList.remove('d-none');

    if (userRow) {
        document.getElementById('siteUserName').innerText =
            userRow.children[1].innerText + " websites";
        document.getElementById('siteUserEmail').innerText =
            userRow.children[2].innerText;
    } else {
        document.getElementById('siteUserName').innerText = 'Publisher websites';
        document.getElementById('siteUserEmail').innerText = '';
    }

    if (addBtn) {
        addBtn.href = `${STAFF_BASE}/sites/create?publisher=${encodeURIComponent(id)}`;
        addBtn.classList.remove('d-none');
    }

    document.getElementById('sitesTable').innerHTML =
        `<tr><td colspan="6">Loading...</td></tr>`;

    const pageNum = Number(page) > 1 ? Number(page) : 1;
    const sitesUrl = pageNum > 1
        ? `${STAFF_BASE}/users/${id}/sites?page=${encodeURIComponent(pageNum)}`
        : `${STAFF_BASE}/users/${id}/sites`;

    return fetch(sitesUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
        .then(async (res) => {
            const contentType = res.headers.get('content-type') || '';
            const isJson = contentType.includes('application/json');

            // Stale sessionStorage publisher ids (or deleted users) 404 here and
            // used to toast on every Sites Management visit. Clear and go back.
            if (res.status === 404) {
                sessionStorage.removeItem('selected_user');
                document.getElementById('sitesSection').classList.add('d-none');
                document.getElementById('usersSection').classList.remove('d-none');
                document.getElementById('sitesTable').innerHTML = '';
                throw new Error('Publisher not found');
            }

            if (!res.ok) {
                let message = 'Failed to load sites';
                if (isJson) {
                    try {
                        const errBody = await res.json();
                        if (errBody?.message) message = errBody.message;
                    } catch (e) { /* keep default */ }
                }
                throw new Error(message);
            }

            if (!isJson) {
                throw new Error('Failed to load sites');
            }

            return res.json();
        })
        .then(data => {
            // Support legacy bare-array responses and the publisher+sites payload.
            const sites = Array.isArray(data) ? data : (data?.sites || []);
            const publisher = Array.isArray(data) ? null : (data?.publisher || null);

            if (publisher) {
                document.getElementById('siteUserName').innerText =
                    (publisher.name || 'Publisher') + ' websites';
                document.getElementById('siteUserEmail').innerText =
                    publisher.email || '';
            }

            const meta = Array.isArray(data) ? null : (data?.meta || null);
            const page = meta && Number(meta.current_page) > 1 ? Number(meta.current_page) : 1;
            if (page > 1) {
                const seen = new Set(allSites.map((s) => s.id));
                sites.forEach((s) => { if (!seen.has(s.id)) allSites.push(s); });
            } else {
                allSites = sites;
            }
            window.sitesListMeta = meta;
            syncPublisherOpenReviewBadge(id, allSites);
            applySiteFilters();
            renderSitesPager(id, meta, allSites.length);
            return allSites;
        })
        .catch((err) => {
            const msg = (err && err.message) ? String(err.message) : 'Failed to load sites';
            // Quietly recover from stale deep links; keep a toast for real failures.
            if (msg !== 'Publisher not found') {
                toast(msg, 'error');
            }
            return [];
        });
}

function renderSitesPager(publisherId, meta, loadedCount) {
    const pager = document.getElementById('sitesPager');
    if (!pager) return;
    if (!meta || Number(meta.last_page) <= 1) {
        pager.innerHTML = '';
        return;
    }
    const loaded = Number(loadedCount) || 0;
    const total = Number(meta.total) || loaded;
    const nextPage = (Number(meta.current_page) || 1) + 1;
    const hasMore = nextPage <= Number(meta.last_page);
    pager.innerHTML = `<span class="small text-muted">Showing ${loaded} of ${total}</span>`
        + (hasMore
            ? `<button type="button" class="btn btn-sm btn-outline-primary" id="sitesLoadMore" data-id="${publisherId}" data-page="${nextPage}">Load more</button>`
            : '');
}

document.addEventListener('click', function (e) {
    const more = e.target.closest('#sitesLoadMore');
    if (!more) return;
    e.preventDefault();
    more.disabled = true;
    fetchUserSites(more.dataset.id, more.dataset.page).finally(() => {
        more.disabled = false;
    });
});

function refreshSidebarQueueBadges() {
    if (typeof window.refreshAdminQueueBadges === 'function') {
        window.refreshAdminQueueBadges();
    }
}

function formatSitesCount(n) {
    const value = Number(n) || 0;
    try {
        return value.toLocaleString('en-US');
    } catch (e) {
        return String(value);
    }
}

function syncPublisherOpenReviewBadge(publisherId, sites) {
    const row = document.querySelector(`.user-row[data-id="${publisherId}"]`);
    if (!row) return;

    const cell = row.querySelector('.admin-sites-count-col') || row.children[3];
    if (!cell) return;

    const list = sites || [];
    const openCount = list.filter(s => !!s.needs_review).length;
    // Prefer the badge's known total so a filtered AJAX list does not shrink it.
    const existingTotal = cell.querySelector('.badge.bg-secondary');
    let totalCount = list.length;
    if (existingTotal) {
        const raw = String(existingTotal.textContent || '').replace(/[^\d]/g, '');
        const parsed = parseInt(raw, 10);
        if (!Number.isNaN(parsed) && parsed > 0) {
            totalCount = Math.max(parsed, list.length);
        }
    }

    const newBadge = openCount > 0
        ? `<span class="badge rounded-pill text-bg-warning" title="Sites waiting for admin decision">${formatSitesCount(openCount)} new</span>`
        : '';
    const totalHtml = `<span class="badge rounded-pill bg-secondary" title="Total sites: ${formatSitesCount(totalCount)}">${formatSitesCount(totalCount)} total</span>`;

    cell.innerHTML = `<div class="admin-sites-count-badges">${newBadge}${totalHtml}</div>`;
}

function revealAllPublisherSites() {
    const needsOnlyEl = document.getElementById('sitesNeedsReviewOnly');
    if (needsOnlyEl && needsOnlyEl.checked) {
        needsOnlyEl.checked = false;
    }
}

function dropNeedsReviewQueryParam() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('needs_review') && url.searchParams.get('verified') !== '0') {
            return;
        }
        url.searchParams.delete('needs_review');
        if (url.searchParams.get('verified') === '0') {
            url.searchParams.delete('verified');
        }
        const next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '');
        window.history.replaceState({}, '', next);
    } catch (e) {
        // ignore
    }
}

function afterSiteDecision() {
    // Verify/Activate removes needs_review — keep the row visible with updated status.
    revealAllPublisherSites();
    dropNeedsReviewQueryParam();
    const userId = sessionStorage.getItem('selected_user');
    if (userId) {
        fetchUserSites(userId);
    }
    refreshSidebarQueueBadges();
}

function applySiteFilters() {
    const searchEl = document.getElementById('siteSearch');
    const needsOnlyEl = document.getElementById('sitesNeedsReviewOnly');
    const val = (searchEl?.value || '').toLowerCase().trim();
    const needsOnly = !!(needsOnlyEl && needsOnlyEl.checked);

    let filtered = allSites.filter(s => {
        if (needsOnly && !s.needs_review) return false;
        if (!val) return true;
        return (s.site_name||'').toLowerCase().includes(val)
            || (s.domain||'').toLowerCase().includes(val)
            || (s.site_url||'').toLowerCase().includes(val)
            || String(s.id || '').includes(val);
    });

    renderSites(filtered);
}

/* ================= EDIT WITH FILE UPLOAD ================= */
function editSiteWithImage(siteId) {
    let site = allSites.find(s => s.id == siteId);
    if (!site) return;

    Swal.fire({
        title: 'Edit Site',
        width: 960,
        showCancelButton: true,
        confirmButtonText: 'Update',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        html: `
            <div style="text-align: left;">
                <label style="font-weight:600; margin-bottom:5px; display:block;">Site Name</label>
                <input id="swal-site_name" class="swal2-input" value="${escapeHtml(site.site_name ?? '')}" placeholder="Site Name">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Site URL</label>
                <input id="swal-site_url" class="swal2-input" value="${escapeHtml(site.site_url ?? '')}" placeholder="Site URL">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">DA (Domain Authority)</label>
                <input id="swal-da" class="swal2-input" type="number" value="${site.da ?? ''}" placeholder="0-100" min="0" max="100" step="1">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">DR (Domain Rating)</label>
                <input id="swal-dr" class="swal2-input" type="number" value="${site.dr ?? ''}" placeholder="0-100" min="0" max="100" step="1">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Traffic (monthly visitors)</label>
                <input id="swal-traffic" class="swal2-input" type="number" value="${site.traffic ?? ''}" placeholder="e.g. 1500000" min="0" max="4294967295" step="1" inputmode="numeric">

                <label style="font-weight:600; margin-bottom:5px; margin-top:14px; display:block;">Site Image (Upload)</label>
                <input type="file" id="swal-site_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" data-max-kb="${SITE_IMAGE_MAX_KB}" data-php-max-kb="${SITE_IMAGE_PHP_MAX_KB}">
                <div id="imagePreviewContainer" class="site-image-desktop-preview ${(site.image_url || site.preview_full_url || site.site_image) ? '' : 'is-empty'}">
                    ${(site.image_url || site.preview_full_url || siteStorageUrl(site.site_image))
                        ? `<img id="imagePreview" src="${escapeHtml(site.image_url || site.preview_full_url || siteStorageUrl(site.site_image))}" alt="Current site image" data-media-fallback="${escapeHtml(siteMediaUrl(site.site_image) || '')}" onerror="if(!this.dataset.triedMedia&&this.dataset.mediaFallback){this.dataset.triedMedia='1';this.src=this.dataset.mediaFallback;}else{this.parentElement.classList.add('is-empty');this.remove();}">`
                        : '<span>No image uploaded — pick a desktop screenshot (16:10, JPEG/PNG/WebP)</span>'}
                </div>
                <small class="text-muted" style="display:block; margin-top:5px; margin-bottom:12px;">Desktop-size preview (16:10). Hover to zoom. JPEG/PNG/GIF/WebP up to ${Math.floor(SITE_IMAGE_MAX_KB / 1024)} MB. Leave empty to keep the current image.</small>
            </div>
        `,
        didOpen: () => {
            const fileInput = document.getElementById('swal-site_image');
            const previewContainer = document.getElementById('imagePreviewContainer');
            const existingSrc = site.image_url || site.preview_full_url || siteStorageUrl(site.site_image);
            const emptyHtml = '<span>No image uploaded — pick a desktop screenshot (16:10, JPEG/PNG/WebP)</span>';

            if (window.SiteImageUpload) {
                window.SiteImageUpload.bindSiteImageInput({
                    input: fileInput,
                    preview: previewContainer,
                    maxKb: SITE_IMAGE_MAX_KB,
                    phpMaxKb: SITE_IMAGE_PHP_MAX_KB,
                    existingSrc: existingSrc || '',
                    emptyHtml: emptyHtml,
                    onError: function (msg) {
                        Swal.showValidationMessage(msg);
                    },
                    onReady: function (file) {
                        if (file && typeof Swal.resetValidationMessage === 'function') {
                            Swal.resetValidationMessage();
                        }
                    },
                });
                return;
            }

            if (fileInput && previewContainer) {
                fileInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        if (file.size > SITE_IMAGE_MAX_KB * 1024) {
                            Swal.showValidationMessage('Site image must be under ' + Math.floor(SITE_IMAGE_MAX_KB / 1024) + ' MB');
                            this.value = '';
                            return;
                        }
                        if (typeof Swal.resetValidationMessage === 'function') {
                            Swal.resetValidationMessage();
                        }
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewContainer.classList.remove('is-empty');
                            previewContainer.innerHTML = `<img src="${e.target.result}" alt="Selected site image">`;
                        };
                        reader.readAsDataURL(file);
                    } else if (existingSrc) {
                        previewContainer.classList.remove('is-empty');
                        previewContainer.innerHTML = `<img src="${existingSrc}" alt="Current site image">`;
                    } else {
                        previewContainer.classList.add('is-empty');
                        previewContainer.innerHTML = emptyHtml;
                    }
                });
            }
        },
        didClose: () => {
            // Run after close animation so body lock / backdrop cannot flash black.
            releaseSwalBodyLock();
        },
        preConfirm: async () => {
            if (typeof Swal.resetValidationMessage === 'function') {
                Swal.resetValidationMessage();
            }

            let site_url = document.getElementById('swal-site_url').value.trim();
            let domain = '';

            try {
                domain = new URL(site_url).hostname.replace('www.', '');
            } catch {
                domain = site_url.replace(/^(https?:\/\/)?(www\.)?/, '').split('/')[0];
            }

            const fileInput = document.getElementById('swal-site_image');
            let file = fileInput?.files?.[0];
            let imagePath = null;
            let imageUrl = null;

            // Upload first when a new file is chosen (persists even before metrics update).
            if (file) {
                if (window.SiteImageUpload) {
                    const prepared = await window.SiteImageUpload.prepareSiteImage(file, SITE_IMAGE_PHP_MAX_KB);
                    if (prepared.error) {
                        Swal.showValidationMessage(prepared.error);
                        return false;
                    }
                    if (prepared.file) {
                        file = prepared.file;
                        window.SiteImageUpload.assignInputFile(fileInput, file);
                    }
                } else if (file.size > SITE_IMAGE_MAX_KB * 1024) {
                    Swal.showValidationMessage('Site image must be under ' + Math.floor(SITE_IMAGE_MAX_KB / 1024) + ' MB');
                    return false;
                }

                const uploadFormData = new FormData();
                uploadFormData.append('site_image', file);
                uploadFormData.append('_token', CSRF_TOKEN);

                try {
                    const uploadResponse = await fetch(`${STAFF_BASE}/sites/${siteId}/upload-image`, {
                        method: 'POST',
                        body: uploadFormData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': CSRF_TOKEN,
                        },
                        credentials: 'same-origin',
                    });

                    let uploadResult = {};
                    try {
                        uploadResult = await uploadResponse.json();
                    } catch (_) {
                        Swal.showValidationMessage('Image upload failed — server returned an unexpected response.');
                        return false;
                    }

                    if (!uploadResponse.ok) {
                        const fieldError = uploadResult?.errors?.site_image?.[0];
                        Swal.showValidationMessage(fieldError || uploadResult.message || 'Image upload failed');
                        return false;
                    }

                    imagePath = uploadResult.image_path || null;
                    imageUrl = uploadResult.image_url || null;
                    if (imagePath || imageUrl) {
                        site.site_image = imagePath || site.site_image;
                        if (imageUrl) {
                            site.image_url = imageUrl;
                            // Keep list/hover in sync until fetchUserSites refreshes rows.
                            site.preview_thumb_url = imageUrl;
                            site.preview_full_url = imageUrl;
                            const prior = Array.isArray(site.preview_fallback_urls)
                                ? site.preview_fallback_urls
                                : [];
                            site.preview_fallback_urls = [imageUrl].concat(
                                prior.filter((u) => u && u !== imageUrl)
                            );
                        }
                    }
                } catch (error) {
                    Swal.showValidationMessage('Error uploading image: ' + error.message);
                    return false;
                }
            }

            return {
                site_name: document.getElementById('swal-site_name').value,
                site_url: site_url,
                domain: domain,
                site_image: imagePath, // null = leave existing image unchanged on update
                da: document.getElementById('swal-da').value,
                dr: document.getElementById('swal-dr').value,
                traffic: document.getElementById('swal-traffic').value,
                _imageUploaded: !!imagePath,
            };
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;
        // Let the dialog + dark backdrop finish closing before feedback/reload.
        await new Promise((resolve) => setTimeout(resolve, 80));
        await submitSiteUpdate(siteId, result.value);
    });
}

async function submitSiteUpdate(siteId, updateData) {
    const imageAlreadySaved = !!(updateData && updateData._imageUploaded);
    const payload = { ...(updateData || {}) };
    delete payload._imageUploaded;
    // Path already persisted by upload-image — omit so a partial PUT cannot clobber it.
    if (imageAlreadySaved) {
        delete payload.site_image;
    }

    try {
        const response = await fetch(`${STAFF_BASE}/sites/${siteId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-HTTP-Method-Override': 'PUT',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        });

        let data = {};
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            try {
                data = await response.json();
            } catch (_) {
                data = {};
            }
        }

        if (response.ok) {
            toast('Updated successfully');
            if (data.email_sent) {
                toast('Email notification sent to publisher', 'info');
            }
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            return;
        }

        // Image was already persisted via upload-image — don't look like a hard failure.
        if (imageAlreadySaved) {
            toast('Image saved. Other fields could not be updated — try again.', 'warning');
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            return;
        }

        toast(data.message || 'Update failed', 'error');
    } catch (error) {
        if (imageAlreadySaved) {
            toast('Image saved. Other fields could not be updated — try again.', 'warning');
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            return;
        }
        toast('Update failed: ' + error.message, 'error');
    } finally {
        releaseSwalBodyLock();
    }
}

/* ================= EVENTS ================= */
document.addEventListener('click', function(e){

    const btn = e.target.closest('.select-user');
    if(btn){
        let id = btn.dataset.id;
        sessionStorage.setItem('selected_user', id);
        // Publishers list may be queue-filtered; always show every site for this publisher.
        revealAllPublisherSites();
        fetchUserSites(id);
        return;
    }

    /* DETAILS expand / collapse */
    if(e.target.closest('.toggle-site-details')){
        const id = e.target.closest('[data-id]').dataset.id;
        const row = document.getElementById('details-' + id);
        if(!row) return;
        setSiteDetailsOpen(id, !row.classList.contains('is-open'));
        return;
    }

    /* EDIT - Using new file upload method */
    if(e.target.closest('.edit-site')){
        let id = e.target.closest('button').dataset.id;
        editSiteWithImage(id);
    }

    /* DELETE / ARCHIVE */
    if(e.target.closest('.delete-site')){
        const btn = e.target.closest('.delete-site');
        let id = btn.dataset.id;
        let site = allSites.find(s => s.id == id);
        const isArchive = canArchiveSiteRow(site) || btn.dataset.archive === '1';
        const name = site?.site_name || 'this site';
        const title = isArchive
            ? 'Archive this site?'
            : (IS_MARKETING_EDITOR ? 'Reject this site?' : 'Delete this site?');
        const text = isArchive
            ? `"${name}" will be hidden from the catalog. Explain why — the publisher will see this reason. The listing is kept so order history stays intact.`
            : (IS_MARKETING_EDITOR
                ? `Explain why "${name}" is being rejected. The publisher will see this reason.`
                : `Are you sure you want to delete "${name}"? Explain why — the publisher will see this reason.`);

        Swal.fire({
            title,
            text,
            icon:'warning',
            input: 'textarea',
            inputLabel: 'Reason for the publisher',
            inputPlaceholder: 'Reason (min. 10 characters)',
            inputAttributes: { 'aria-label': isArchive ? 'Archive reason' : 'Rejection reason', maxlength: '1000' },
            showCancelButton:true,
            confirmButtonText: isArchive ? 'Archive' : (IS_MARKETING_EDITOR ? 'Reject' : 'Delete'),
            customClass: { confirmButton: 'slb-swal-danger' },
            preConfirm: (value) => {
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if(!result.isConfirmed) return;

            const reason = String(result.value || '').trim();
            fetch(`${STAFF_BASE}/sites/${id}`, {
                method:'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ reason }),
            })
            .then(async (res) => {
                let data = {};
                try {
                    data = await res.json();
                } catch (_) {
                    throw new Error(`Failed to delete site (${res.status})`);
                }

                if (!res.ok || !data.success) {
                    const reasonErr = data.errors && data.errors.reason
                        ? (Array.isArray(data.errors.reason) ? data.errors.reason[0] : data.errors.reason)
                        : null;
                    throw new Error(reasonErr || data.message || (isArchive ? 'Could not archive site' : 'Failed to delete site'));
                }

                toast(data.message || (data.archived ? 'Site archived' : 'Deleted successfully'));
                afterSiteDecision();
            })
            .catch((error) => {
                toast(error.message || (isArchive ? 'Could not archive site' : 'Failed to delete site'), 'error');
            });
        });
    }

    /* TOGGLE ACTIVE */
    if(e.target.closest('.toggle-active')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;
        let activating = Number(status) === 1;
        let newStatus = activating ? 'activate' : 'deactivate';
        let needsReason = !activating;

        const postActive = (payload) => {
            fetch(`${STAFF_BASE}/sites/${id}/active`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json',
                    'X-CSRF-TOKEN':'{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            })
            .then(async (res) => {
                let data = {};
                try {
                    data = await res.json();
                } catch (_) {
                    throw new Error(`Failed to ${newStatus} site (${res.status})`);
                }

                if(!res.ok || !data.success) {
                    const reasonErr = data.errors && data.errors.reason
                        ? (Array.isArray(data.errors.reason) ? data.errors.reason[0] : data.errors.reason)
                        : null;
                    const msg = reasonErr || data.message || `Failed to ${newStatus} site`;
                    throw new Error(msg);
                }

                toast(data.message || (activating ? 'Site activated successfully' : 'Site deactivated successfully'));
                if (data.warning) {
                    toast(data.warning, 'warning');
                }
                if(data.email_sent) {
                    toast('Email notification sent to publisher', 'info');
                }
                afterSiteDecision();
            })
            .catch((error) => {
                toast(error.message || `Failed to ${newStatus} site`, 'error');
            });
        };

        if (activating) {
            const site = allSites.find((s) => String(s.id) === String(id)) || {};
            const activateOpts = {
                looksEnglish: site.description_looks_english,
                excerpt: site.description_excerpt || '',
                name: site.site_name || '',
                editUrl: `${STAFF_BASE}/sites/${id}/edit#description`,
            };
            const fallbackActivateText = activateOpts.name
                ? 'Make "' + activateOpts.name + '" live in the catalog?'
                : 'Are you sure you want to activate this site?';
            const confirmActivate = (typeof window.slbConfirmActivate === 'function')
                ? window.slbConfirmActivate(activateOpts)
                : (typeof window.slbConfirm === 'function')
                    ? window.slbConfirm({
                        title: 'Activate Site?',
                        text: fallbackActivateText,
                        icon: 'question',
                        confirmText: 'Yes, activate',
                    })
                    : Swal.fire({
                        title: 'Activate Site?',
                        text: fallbackActivateText,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, activate',
                    }).then((result) => !!(result && result.isConfirmed));
            confirmActivate.then((ok) => {
                if (!ok) return;
                postActive({ active: 1 });
            });
            return;
        }

        Swal.fire({
            title: 'Deactivate Site?',
            text: 'Explain why this listing is being deactivated. The publisher will see this reason in email and notifications.',
            icon: 'question',
            input: needsReason ? 'textarea' : undefined,
            inputLabel: needsReason ? 'Reason for the publisher' : undefined,
            inputPlaceholder: needsReason ? 'Reason (min. 10 characters)' : undefined,
            inputAttributes: needsReason ? { 'aria-label': 'Deactivation reason', maxlength: '1000' } : undefined,
            showCancelButton: true,
            confirmButtonText: 'Yes, deactivate',
            preConfirm: (value) => {
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if(!result.isConfirmed) return;
            const reason = String(result.value || '').trim();
            if (reason.length < 10) {
                toast('A deactivation reason is required (min. 10 characters).', 'error');
                return;
            }
            postActive({ active: 0, reason: reason });
        });
    }

    /* TOGGLE VERIFY */
    if(e.target.closest('.toggle-verify')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;
        let newStatus = status == 1 ? 'verify' : 'unverify';
        let needsReason = newStatus === 'unverify';

        Swal.fire({
            title: `${newStatus === 'verify' ? 'Verify' : 'Unverify'} Site?`,
            text: needsReason
                ? 'Explain why verification is being removed. The publisher will see this reason.'
                : `Are you sure you want to ${newStatus} this site?`,
            icon: 'question',
            input: needsReason ? 'textarea' : undefined,
            inputPlaceholder: needsReason ? 'Reason (min. 10 characters)' : undefined,
            inputAttributes: needsReason ? { 'aria-label': 'Unverify reason' } : undefined,
            showCancelButton: true,
            confirmButtonText: `Yes, ${newStatus}`,
            preConfirm: (value) => {
                if (!needsReason) return null;
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if(!result.isConfirmed) return;

            const payload = { verified: Number(status) === 1 ? 1 : 0 };
            if (needsReason && result.value) {
                payload.reason = result.value;
            }

            fetch(`${STAFF_BASE}/sites/${id}/verify`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json',
                    'X-CSRF-TOKEN':'{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            })
            .then(async (res) => {
                let data = {};
                try {
                    data = await res.json();
                } catch (_) {
                    throw new Error(`Failed to ${newStatus} site (${res.status})`);
                }

                if(!res.ok || !data.success) {
                    const msg = data.message
                        || (data.errors && data.errors.reason && data.errors.reason[0])
                        || `Failed to ${newStatus} site`;
                    throw new Error(msg);
                }

                toast(`Site ${newStatus}d successfully`);
                if(data.email_sent) {
                    toast(`Email notification sent to publisher`, 'info');
                }
                afterSiteDecision();
            })
            .catch((error) => {
                toast(error.message || `Failed to ${newStatus} site`, 'error');
            });
        });
    }
});

/* ================= ENRICHMENT ================= */
document.addEventListener('click', async function(e){
    const enrichBtn = e.target.closest('.enrich-site');
    const shotBtn = e.target.closest('.refresh-screenshot');
    const unlockBtn = e.target.closest('.allow-api-overwrite');
    if(!enrichBtn && !shotBtn && !unlockBtn) return;

    const btn = enrichBtn || shotBtn || unlockBtn;
    const id = btn.dataset.id;
    const url = unlockBtn
        ? `${STAFF_BASE}/sites/${id}/allow-api-metrics`
        : (enrichBtn ? `${STAFF_BASE}/sites/${id}/enrich` : `${STAFF_BASE}/sites/${id}/refresh-screenshot`);
    btn.disabled = true;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            // Queue jobs — sync capture blocks the UI for tens of seconds.
            body: JSON.stringify(unlockBtn ? {} : { sync: false }),
        });
        const data = await res.json();
        const okLabel = unlockBtn ? 'API overwrite allowed' : (enrichBtn ? 'Enrichment queued' : 'Screenshot queued');
        toast(
            data.message || (data.success ? okLabel : 'Failed'),
            data.success ? 'success' : 'error'
        );
        // Do not reload the whole publisher list after queueing — keep the UI snappy.
    } catch (err) {
        toast(unlockBtn ? 'Could not unlock API overwrite' : 'Enrichment request failed', 'error');
    } finally {
        btn.disabled = false;
    }
});

/* ================= HELPER ================= */
function escapeHtml(str) {
    if(!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ================= RENDER ================= */
function siteStorageUrl(path) {
    if (!path) return null;
    const raw = String(path);
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/storage/') || raw.startsWith('/media/') || raw.includes('/sites/media/')) {
        return raw;
    }
    return `/storage/${raw.replace(/^\/+/, '')}`;
}

function siteMediaUrl(path) {
    if (!path) return null;
    const raw = String(path);
    if (/^https?:\/\//i.test(raw) || raw.includes('/sites/media/')) {
        return raw;
    }
    if (raw.startsWith('/storage/')) {
        return `${STAFF_BASE}/sites/media/${raw.slice('/storage/'.length)}`;
    }
    if (raw.startsWith('/media/')) {
        return `${STAFF_BASE}/sites/media/${raw.slice('/media/'.length)}`;
    }
    return `${STAFF_BASE}/sites/media/${raw.replace(/^\/+/, '')}`;
}

function sitePreviewPaths(site) {
    // Prefer API-built disk-aware URLs. Avoid inventing /storage/ paths that 404.
    const chain = [];
    const push = (url) => {
        if (!url) return;
        const u = String(url);
        if (u && !chain.includes(u)) chain.push(u);
    };

    const uploaded = site.image_url || siteMediaUrl(site.site_image) || siteStorageUrl(site.site_image);
    // Prefer staff media stream first (Hostinger /storage often 404s).
    push(siteMediaUrl(site.site_image));
    push(uploaded);
    push(siteStorageUrl(site.site_image));

    const apiFallbacks = Array.isArray(site.preview_fallback_urls)
        ? site.preview_fallback_urls
        : null;

    if (apiFallbacks !== null) {
        apiFallbacks.forEach(push);
        push(site.preview_thumb_url);
        push(site.preview_full_url);
        push(site.screenshot_thumb_url);
        push(site.screenshot_url);
        push(site.image_url);
        push(siteMediaUrl(site.site_image));
        push(siteStorageUrl(site.site_image));
    } else {
        // Legacy payload without disk checks.
        push(site.preview_thumb_url);
        push(site.preview_full_url);
        push(site.screenshot_thumb_url);
        push(site.screenshot_url);
        push(site.image_url);
        push(siteMediaUrl(site.screenshot_thumb_path));
        push(siteStorageUrl(site.screenshot_thumb_path));
        push(siteMediaUrl(site.screenshot_path));
        push(siteStorageUrl(site.screenshot_path));
        push(siteMediaUrl(site.site_image));
        push(siteStorageUrl(site.site_image));
    }

    const thumb = siteMediaUrl(site.site_image) || uploaded || site.preview_thumb_url || site.screenshot_thumb_url || chain[0] || null;
    const full = site.preview_full_url || site.screenshot_url || siteMediaUrl(site.site_image) || uploaded || thumb || null;

    if (thumb) push(thumb);
    if (full) push(full);

    return { thumb, full, chain };
}

function markSitePreviewBroken(img) {
    const parent = img && img.parentElement;
    if (!parent) return;
    parent.classList.add('is-empty');
    parent.removeAttribute('data-zoom-src');
    parent.removeAttribute('tabindex');
    parent.innerHTML = '<i class="fa fa-image" aria-hidden="true"></i>';
}

function sitePreviewImgOnError(img) {
    let chain = [];
    try {
        chain = JSON.parse(img.getAttribute('data-preview-chain') || '[]');
    } catch (e) {
        chain = [];
    }
    const next = Number(img.getAttribute('data-preview-i') || '0') + 1;
    if (next < chain.length) {
        img.setAttribute('data-preview-i', String(next));
        img.src = chain[next];
        return;
    }
    img.onerror = null;
    markSitePreviewBroken(img);
}

function sitePreviewHtml(site) {
    const paths = sitePreviewPaths(site);
    if (!paths.thumb) {
        return `<span class="site-row-preview is-empty" aria-label="No preview"><i class="fa fa-image" aria-hidden="true"></i></span>`;
    }

    const name = escapeHtml(site.site_name || 'Site');
    // Zoom uses full only on hover (loaded then) — keep list src on the light thumb.
    const zoomAttr = paths.full ? ` data-zoom-src="${escapeHtml(paths.full)}" tabindex="0"` : '';
    // Prefer thumb → upload → full so a missing thumb recovers without fetching the desktop shot first.
    const chain = [];
    [paths.thumb, siteMediaUrl(site.site_image), site.image_url || siteStorageUrl(site.site_image), paths.full]
        .concat(paths.chain || [])
        .forEach(function (url) {
            if (url && !chain.includes(url)) chain.push(url);
        });
    const chainJson = escapeHtml(JSON.stringify(chain));

    return `
        <span class="site-row-preview"
              role="img"
              aria-label="${name} preview"${zoomAttr}>
            <img src="${escapeHtml(chain[0] || paths.thumb)}"
                 alt="${name} preview"
                 loading="lazy"
                 decoding="async"
                 data-preview-chain="${chainJson}"
                 data-preview-i="0"
                 onerror="sitePreviewImgOnError(this)">
        </span>
    `;
}

function syncSiteDetailsLabel(id, opening) {
    const label = document.querySelector(`#sitesTable .toggle-site-details[data-id="${id}"]`);
    if (!label) return;
    label.innerHTML = opening
        ? '<i class="fa fa-chevron-up me-2"></i>Hide details'
        : '<i class="fa fa-chevron-down me-2"></i>Details';
}

function setSiteDetailsOpen(id, opening) {
    const row = document.getElementById('details-' + id);
    if (!row) return false;
    if (opening) {
        document.querySelectorAll('#sitesTable .admin-expand-row.is-open').forEach(function (openRow) {
            if (openRow === row) return;
            openRow.classList.remove('is-open');
            const otherId = String(openRow.id || '').replace(/^details-/, '');
            if (otherId) {
                syncSiteDetailsLabel(otherId, false);
            }
        });
        row.classList.add('is-open');
        hydrateSiteDetailImages(row);
    } else {
        row.classList.remove('is-open');
    }
    syncSiteDetailsLabel(id, opening);
    return true;
}

function hydrateSiteDetailImages(scope) {
    (scope || document).querySelectorAll('img[data-detail-src]').forEach(function (img) {
        const src = img.getAttribute('data-detail-src');
        if (!src || img.getAttribute('src')) return;
        img.setAttribute('src', src);
        img.removeAttribute('data-detail-src');
        // If staff/storage URL 404s, retry via the other known public paths.
        if (!img.getAttribute('onerror')) {
            img.onerror = function () {
                const src = String(this.src || '');
                if (!this.dataset.triedStaff && src.includes('/storage/')) {
                    this.dataset.triedStaff = '1';
                    this.src = src.replace('/storage/', (typeof STAFF_BASE !== 'undefined' ? STAFF_BASE : '/admin') + '/sites/media/');
                    return;
                }
                if (!this.dataset.triedPublicMedia && src.includes('/sites/media/')) {
                    this.dataset.triedPublicMedia = '1';
                    this.src = '/media/' + src.split('/sites/media/').pop();
                    return;
                }
                if (!this.dataset.triedStorage && src.includes('/media/') && !src.includes('/sites/media/')) {
                    this.dataset.triedStorage = '1';
                    this.src = src.replace('/media/', '/storage/');
                    return;
                }
                if (this.parentElement) {
                    this.parentElement.style.display = 'none';
                }
            };
        }
    });
}

function initSitePreviewZoom(root) {
    const scope = root || document;
    if (window.SiteImageUpload && !window.SiteImageUpload.canHoverZoom()) return;
    if (!window.SiteImageUpload && window.matchMedia && !window.matchMedia('(any-hover: hover)').matches && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    let pop = document.getElementById('sitePreviewZoomPop');
    if (!pop) {
        pop = document.createElement('div');
        pop.id = 'sitePreviewZoomPop';
        pop.className = 'site-preview-zoom-pop';
        pop.setAttribute('aria-hidden', 'true');
        pop.innerHTML = '<img alt="" decoding="async">';
        document.body.appendChild(pop);
    }
    const img = pop.querySelector('img');
    let hideTimer = null;

    function place(trigger) {
        const rect = trigger.getBoundingClientRect();
        const pad = 12;
        const popW = pop.offsetWidth || 720;
        const popH = pop.offsetHeight || 450;
        let left = rect.right + 12;
        let top = rect.top + (rect.height / 2) - (popH / 2);
        if (left + popW > window.innerWidth - pad) {
            left = rect.left - popW - 12;
        }
        if (left < pad) left = pad;
        if (top < pad) top = pad;
        if (top + popH > window.innerHeight - pad) {
            top = Math.max(pad, window.innerHeight - popH - pad);
        }
        pop.style.left = Math.round(left) + 'px';
        pop.style.top = Math.round(top) + 'px';
    }

    function show(trigger) {
        const src = trigger.getAttribute('data-zoom-src');
        if (!src || trigger.classList.contains('is-empty')) return;
        clearTimeout(hideTimer);
        if (img.getAttribute('src') !== src) {
            img.setAttribute('src', src);
        }
        img.setAttribute('alt', trigger.getAttribute('aria-label') || 'Site preview');
        pop.classList.add('is-visible');
        place(trigger);
        requestAnimationFrame(function () { place(trigger); });
    }

    function hide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            pop.classList.remove('is-visible');
        }, 80);
    }

    scope.querySelectorAll('.site-row-preview[data-zoom-src]').forEach(function (el) {
        if (el.getAttribute('data-zoom-ready') === '1') return;
        el.setAttribute('data-zoom-ready', '1');
        el.addEventListener('mouseenter', function () { show(el); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function () { show(el); });
        el.addEventListener('blur', hide);
    });
}

function renderSites(data){

    data = [...(data || [])].sort((a,b) => (b.id || 0) - (a.id || 0));

    let html = '';

    if(!data.length){
        html = `<tr><td colspan="6" class="text-center text-muted">No sites found</td></tr>`;
    } else {

        data.forEach((site,i) => {
            const paths = sitePreviewPaths(site);

            const needsReview = !!site.needs_review;
            const reviewBadge = needsReview
                ? `<span class="badge text-bg-warning badge-needs-review ms-1">NEW · Needs review</span>`
                : '';
            const awaitingBadge = site.awaits_publisher_details
                ? `<span class="badge text-bg-secondary badge-needs-review ms-1">Awaiting publisher</span>`
                : '';
            const inviteBadge = site.pending_publisher_acceptance
                ? `<span class="badge text-bg-info badge-needs-review ms-1">Awaiting accept</span>`
                : '';
            const csvMetricsBadge = site.csv_metrics_spot_check
                ? `<span class="badge text-bg-light border badge-needs-review ms-1" title="Publisher-supplied DA/DR/traffic from agency CSV — spot-check before activate">CSV metrics — spot-check</span>`
                : '';
            const missingMarketBadge = site.missing_market
                ? `<span class="badge text-bg-danger badge-needs-review ms-1" title="Set a marketplace country before marketing can activate">Missing market</span>`
                : '';
            const belowQualityBadge = site.below_quality_bar
                ? `<span class="badge text-bg-warning text-dark badge-needs-review ms-1" title="DA ≥ ${QUALITY_MIN_DA}, DR ≥ ${QUALITY_MIN_DR}, traffic ≥ ${QUALITY_MIN_TRAFFIC.toLocaleString('en-US')}">Below quality bar</span>`
                : '';

            // Publisher-style 16:10 preview + site identity
            let siteInfoHtml = `
                <div class="site-info-cell admin-site-info-stack">
                    ${sitePreviewHtml(site)}
                    <div class="site-details">
                        <div class="site-name">
                            ${escapeHtml(site.site_name ?? '-')}
                            ${reviewBadge}
                            ${awaitingBadge}
                            ${inviteBadge}
                            ${csvMetricsBadge}
                            ${missingMarketBadge}
                            ${belowQualityBadge}
                        </div>
                        <a href="${escapeHtml(site.site_url ?? '#')}" target="_blank" class="site-url" title="${escapeHtml(site.site_url ?? '')}">
                            ${escapeHtml(site.site_url ?? '-')}
                        </a>
                    </div>
                </div>
            `;

            const isActive = Number(site.active) === 1 || site.active === true;
            const isVerified = Number(site.verified) === 1 || site.verified === true;

            const statusHtml = `
                <div class="admin-status-stack">
                    <span>${isActive
                        ? '<span class="pulse-dot pulse-green"></span>Active'
                        : '<span class="pulse-dot pulse-red"></span>Inactive'}</span>
                    <span class="badge rounded-pill ${isVerified ? 'bg-success' : 'bg-secondary'}">
                        ${isVerified ? 'Verified' : 'Unverified'}
                    </span>
                </div>
            `;

            const listingLocked = IS_MARKETING_EDITOR && (
                isVerified
                || isActive
                || !!site.listing_locked
            );
            const editItem = `<li><a class="dropdown-item" href="${STAFF_BASE}/sites/${site.id}/edit"><i class="fa fa-edit me-2"></i>Edit</a></li>`
                + (IS_MARKETING_EDITOR
                    ? ''
                    : `<li><button type="button" class="dropdown-item edit-site" data-id="${site.id}"><i class="fa fa-image me-2"></i>Metrics &amp; image</button></li>`);
            const enrichItems = (IS_MARKETING_EDITOR && listingLocked)
                ? ''
                : `<li><button type="button" class="dropdown-item enrich-site" data-id="${site.id}"><i class="fa fa-sync me-2"></i>Enrich</button></li>
                        <li><button type="button" class="dropdown-item refresh-screenshot" data-id="${site.id}"><i class="fa fa-camera me-2"></i>Shot</button></li>`
                    + (site.metrics_manual
                        ? `<li><button type="button" class="dropdown-item allow-api-overwrite" data-id="${site.id}"><i class="fa fa-unlock me-2"></i>Allow API overwrite</button></li>`
                        : '');

            const deleteItem = canDeleteSiteRow(site)
                ? `<li><button type="button" class="dropdown-item text-danger delete-site" data-id="${site.id}"><i class="fa fa-trash me-2"></i>Delete</button></li>`
                : (canArchiveSiteRow(site)
                    ? `<li><button type="button" class="dropdown-item text-danger delete-site" data-id="${site.id}" data-archive="1"><i class="fa fa-archive me-2"></i>Archive</button></li>`
                    : (CAN_DELETE_ANY_SITE && siteHasOrders(site) && !site.archived
                        ? `<li><button type="button" class="dropdown-item disabled" disabled title="This listing has orders. Deactivate it to hide it from the catalog."><i class="fa fa-ban me-2"></i>Has orders — deactivate instead</button></li>`
                        : ''));

            // Always offer Deactivate after Activate. Hide Activate when the
            // listing cannot go live (server also 422s the same rules).
            const activateBlocked = site.can_activate === false;
            const activateBlockReason = site.activate_block_reason || 'Cannot activate this listing yet.';
            const activeItem = CAN_TOGGLE_ACTIVE
                ? (isActive
                    ? `<li><button type="button" class="dropdown-item toggle-active" data-id="${site.id}" data-status="0"><i class="fa fa-pause me-2"></i>Deactivate</button></li>`
                    : (activateBlocked
                        ? `<li><button type="button" class="dropdown-item disabled" disabled title="${escapeHtml(activateBlockReason)}"><i class="fa fa-ban me-2"></i>Cannot activate</button></li>`
                        : `<li><button type="button" class="dropdown-item toggle-active" data-id="${site.id}" data-status="1"><i class="fa fa-play me-2"></i>Activate</button></li>`))
                : '';

            const verifyItem = CAN_VERIFY_SITES
                ? (isVerified
                    ? `<li><button type="button" class="dropdown-item toggle-verify" data-id="${site.id}" data-status="0"><i class="fa fa-times me-2"></i>Unverify</button></li>`
                    : `<li><button type="button" class="dropdown-item toggle-verify" data-id="${site.id}" data-status="1"><i class="fa fa-check me-2"></i>Verify</button></li>`)
                : '';

            const managePopperConfig = JSON.stringify({
                strategy: 'fixed',
                modifiers: [
                    { name: 'preventOverflow', options: { boundary: 'viewport', padding: 8 } },
                    { name: 'flip', options: { fallbackPlacements: ['top-end', 'bottom-end', 'top', 'bottom'] } },
                ],
            });

            const manageHtml = `
                <div class="dropdown admin-manage-dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                            data-bs-toggle="dropdown"
                            data-bs-auto-close="true"
                            data-bs-popper-config='${managePopperConfig}'
                            aria-expanded="false"
                            aria-haspopup="true">
                        Manage
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end admin-manage-menu">
                        ${editItem}
                        ${deleteItem}
                        ${(activeItem || verifyItem) ? '<li><hr class="dropdown-divider"></li>' : ''}
                        ${activeItem}
                        ${verifyItem}
                        ${enrichItems ? '<li><hr class="dropdown-divider"></li>' + enrichItems : ''}
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item toggle-site-details" data-id="${site.id}"><i class="fa fa-chevron-down me-2"></i>Details</button></li>
                    </ul>
                </div>
            `;

            html += `
                <tr class="${needsReview ? 'site-needs-review-row' : ''}" data-site-row="${site.id}">
                    <td>${i+1}</td>
                    <td>${siteInfoHtml}</td>
                    <td>${site.traffic ?? '-'}</td>
                    <td>€${site.price ?? '-'}</td>
                    <td>${statusHtml}</td>
                    <td>${manageHtml}</td>
                </tr>

                <tr id="details-${site.id}" class="admin-expand-row">
                    <td colspan="6">
                        <div class="admin-expand-box">
                            <div class="border rounded bg-white shadow-sm p-3">
                                <div class="row g-3">
                                    <div class="col-md-4"><strong>Domain</strong><div class="slb-text-break">${escapeHtml(site.domain ?? '-')}</div></div>
                                    <div class="col-md-4"><strong>DA/DR</strong><div>${site.da ?? '-'} / ${site.dr ?? '-'}</div></div>
                                    <div class="col-md-4"><strong>Traffic</strong><div>${site.traffic ?? '-'}</div></div>
                                    <div class="col-md-4"><strong>Enrichment</strong><div>${escapeHtml(site.enrichment_status ?? 'pending')}${site.metrics_fetched_at ? ' · metrics ' + new Date(site.metrics_fetched_at).toLocaleString() : ''}</div></div>
                                    <div class="col-md-4"><strong>Screenshot</strong><div>${(paths.full || paths.thumb) ? `<div class="site-preview-detail"><img data-detail-src="${escapeHtml(paths.full || paths.thumb)}" alt="Site preview" loading="lazy" decoding="async" onerror="this.parentElement.style.display='none'"></div>` : '—'}</div></div>
                                    ${site.enrichment_error ? `<div class="col-12"><strong>Last scan error</strong><div class="text-danger small slb-text-break">${escapeHtml(site.enrichment_error)}</div></div>` : ''}
                                    <div class="col-md-4"><strong>Countries</strong><div>${(site.countries && site.countries.length ? site.countries : [site.country]).filter(Boolean).map(c => String(c).toUpperCase()).join(', ') || '-'}</div></div>
                                    <div class="col-md-4"><strong>Languages</strong><div>${(site.languages && site.languages.length ? site.languages : [site.language]).filter(Boolean).map(l => String(l).toUpperCase()).join(', ') || '-'}</div></div>
                                    <div class="col-md-4"><strong>Category</strong><div>${escapeHtml(site.category ?? '-')}</div></div>
                                    <div class="col-md-4"><strong>Link Type</strong><div>${site.link_type ?? '-'}</div></div>
                                    <div class="col-md-4"><strong>Sponsored</strong><div>${site.sponsored ? 'Yes':'No'}</div></div>
                                    <div class="col-md-4"><strong>Price</strong><div>€${site.price ?? '-'}</div></div>
                                    <div class="col-12"><strong>Description</strong><div class="slb-text-break">${escapeHtml(site.description ?? '-')}</div></div>
                                    ${(site.image_url || siteMediaUrl(site.site_image) || siteStorageUrl(site.site_image)) ? `<div class="col-12"><strong>Site Image</strong><div class="site-preview-detail"><img data-detail-src="${escapeHtml(site.image_url || siteMediaUrl(site.site_image) || siteStorageUrl(site.site_image))}" alt="Site image" loading="lazy" decoding="async"></div></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        });
    }

    document.getElementById('sitesTable').innerHTML = html;
    initSitePreviewZoom(document.getElementById('sitesTable'));

    if (pendingHighlightSiteId) {
        const highlightId = String(pendingHighlightSiteId);
        pendingHighlightSiteId = null;
        const row = document.querySelector(`[data-site-row="${highlightId}"]`);
        if (row) {
            row.classList.add('site-highlight-row');
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            // CSS reveals the panel via .is-open, not .d-none.
            setSiteDetailsOpen(highlightId, true);
        }
    }
}

/* ================= BACK ================= */
document.getElementById('backBtn').addEventListener('click', function(){
    document.getElementById('sitesSection').classList.add('d-none');
    const usersSection = document.getElementById('usersSection');
    if (usersSection) {
        usersSection.classList.remove('d-none');
    }
    sessionStorage.removeItem('selected_user');
    // Drop deep-link params so refresh stays on the publisher list (not stuck on sites).
    try {
        const url = new URL(window.location.href);
        ['publisher', 'site', 'edit_site'].forEach((key) => url.searchParams.delete(key));
        const next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '');
        window.history.replaceState({}, '', next);
    } catch (e) {}
    // Clear any leftover SweetAlert body lock after image edit/save.
    releaseSwalBodyLock();
});

/* ================= SEARCH (Catalog-parity live search) ================= */
/* Publisher search is server-side (?q=) via data-slb-live-search="form". */
/* Site-row search stays client-side against the loaded publisher list. */
(function initStaffSitesLiveSearch() {
    function boot() {
        if (typeof window.SlbLiveSearch !== 'undefined') {
            window.SlbLiveSearch.init(document.getElementById('siteSearch'), {
                mode: 'client',
                statusEl: document.getElementById('siteSearchStatus'),
                clearBtn: document.getElementById('siteSearchClear'),
                onSearch: function () { applySiteFilters(); },
            });
            return;
        }
        document.getElementById('siteSearch')?.addEventListener('keyup', function(){
            applySiteFilters();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

document.getElementById('sitesNeedsReviewOnly')?.addEventListener('change', function(){
    applySiteFilters();
});

/* ================= RESTORE / DEEP-LINK ================= */
window.addEventListener('DOMContentLoaded',()=>{
    const params = new URLSearchParams(window.location.search);
    const editSiteId = params.get('edit_site');
    const siteId = params.get('site');

    // Asking for the review queue is an explicit request for the list. The last
    // publisher opened is remembered so a refresh returns you to them, but that
    // memory was also restored here — so clicking "Needs review" fetched the
    // queue, then immediately covered it with whichever publisher you happened
    // to open last, and the button looked dead.
    const wantsReviewQueue = params.has('needs_review') || params.get('verified') === '0';
    if (wantsReviewQueue && !params.get('publisher') && !siteId) {
        sessionStorage.removeItem('selected_user');
    }

    const publisherId = params.get('publisher') || sessionStorage.getItem('selected_user');

    if (siteId) {
        pendingHighlightSiteId = siteId;
    }

    // Opening a publisher detail (deep link, notification, or session restore) must
    // show activated/verified sites — never re-apply the queue-only client filter.
    if (publisherId || siteId) {
        revealAllPublisherSites();
    }

    if (publisherId) {
        sessionStorage.setItem('selected_user', publisherId);
        fetchUserSites(publisherId).then(() => {
            if (editSiteId) {
                window.location.href = `${STAFF_BASE}/sites/${editSiteId}/edit`;
                return;
            }
        });
        return;
    }

    let id = sessionStorage.getItem('selected_user');
    if(id && !FLAT_QUEUE) {
        revealAllPublisherSites();
        fetchUserSites(id);
    }
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-mkt-activate');
    if (!btn) return;
    e.preventDefault();
    const id = btn.dataset.id;
    const name = btn.dataset.name || 'this site';
    const go = (typeof window.slbConfirmActivate === 'function')
        ? window.slbConfirmActivate({
            looksEnglish: btn.dataset.descriptionEnglish !== '0',
            excerpt: btn.dataset.descriptionExcerpt || '',
            name: name,
            confirmText: 'Activate',
            editUrl: `${STAFF_BASE}/sites/${id}/edit#description`,
        })
        : window.slbConfirm({
            title: 'Activate Site?',
            text: 'Make "' + name + '" live in the catalog?',
            icon: 'question',
            confirmText: 'Activate',
        });
    go.then((ok) => {
        if (!ok) return;
        fetch(`${STAFF_BASE}/sites/${id}/active`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ active: 1 }),
        })
        .then((res) => res.json().then((data) => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (ok && data && data.success) {
                window.location.reload();
                return;
            }
            toast((data && data.message) || 'Could not activate site', 'error');
        })
        .catch(() => toast('Could not activate site', 'error'));
    });
});
</script>

@endsection