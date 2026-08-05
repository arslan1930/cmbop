@extends(staff_layout())

@section('content')
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
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if(!empty($needsReviewFilterActive))
                <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-dark">
                    Show all publishers
                </a>
            @else
                <a href="{{ staff_route('sites.index', ['needs_review' => 1]) }}" class="btn btn-sm btn-warning">
                    <i class="fa fa-bell me-1"></i> Needs review
                    @if(($openReviewCount ?? 0) > 0)
                        <span class="badge text-bg-dark ms-1">{{ $openReviewCount }}</span>
                    @endif
                </a>
            @endif
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('admin.sites.records') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-table me-1"></i> Websites records sheet
                </a>
                <a href="{{ staff_route('site-enrichment.index') }}" class="btn btn-sm btn-outline-primary">
                    Enrichment &amp; scan failures
                </a>
            @endif
        </div>
    </div>

    @if(!empty($needsReviewFilterActive) || !empty($unverifiedFilter))
        <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Needs review queue</strong>
                <span class="ms-1">Publishers with new/ready sites waiting for Verify, Activate, Reject, or Delete. Reminders stay until you decide.</span>
            </div>
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-dark">Show all publishers</a>
        </div>
    @endif

    <!-- ================= USERS TABLE ================= -->
    <div id="usersSection">

        <div class="mb-2" style="max-width: 250px;">
            <input type="text" id="userSearch" class="form-control form-control-sm" placeholder="Search users...">
        </div>

        <div class="card shadow-sm border-0 mb-3 admin-table-fit">
            <div class="card-header bg-white fw-semibold">
                {{ !empty($needsReviewFilterActive) || !empty($unverifiedFilter) ? 'Publishers with sites needing review' : 'Users' }}
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col">#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Sites</th>
                            <th class="admin-actions-col">Action</th>
                        </tr>
                    </thead>

                    <tbody id="usersTable">
                    @forelse($users as $index => $user)
                        <tr class="user-row" data-id="{{ $user->id }}" style="height:60px;">
                            <td>{{ $users->firstItem() + $index }}</td>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td class="slb-text-break">{{ $user->email }}</td>
                            <td>
                                @php
                                    $needsReviewCount = (int) ($user->needs_review_sites_count
                                        ?? $user->unverified_sites_count
                                        ?? $user->sites->filter(fn ($s) => $s->needsAdminReview())->count());
                                @endphp
                                @if($needsReviewCount > 0)
                                    <span class="badge rounded-pill text-bg-warning" title="Sites waiting for admin decision">
                                        {{ $needsReviewCount }} new
                                    </span>
                                @endif
                                <span class="badge rounded-pill bg-secondary ms-1" title="Total sites">
                                    {{ $user->sites_count }} total
                                </span>
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
                            <td colspan="5" class="text-center text-muted">No users found</td>
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

            <button class="btn btn-sm btn-outline-secondary" id="backBtn">
                ← Back
            </button>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div style="max-width: 250px;">
                <input type="text" id="siteSearch" class="form-control form-control-sm" placeholder="Search sites...">
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

        </div>

    </div>

</div>


<script>
const STAFF_BASE = @json(staff_base_path());
const CAN_DELETE_ANY_SITE = @json(auth()->user()->isAdmin());
const CAN_DELETE_PENDING_SITES = @json(auth()->user()->isAdmin() || auth()->user()->isMarketing());
const CAN_VERIFY_SITES = @json(auth()->user()->isAdmin());
const CAN_TOGGLE_ACTIVE = @json(auth()->user()->canActivateSites());
const IS_MARKETING_EDITOR = @json(auth()->user()->isMarketing() && ! auth()->user()->isAdmin());
let allSites = [];
let pendingHighlightSiteId = null;

function canDeleteSiteRow(site) {
    if (CAN_DELETE_ANY_SITE) return true;
    if (!CAN_DELETE_PENDING_SITES) return false;
    const verified = Number(site?.verified) === 1 || site?.verified === true;
    const active = Number(site?.active) === 1 || site?.active === true;
    return !verified && !active;
}

/* ================= TOAST ================= */
function toast(msg, icon='success'){
    showAppToast(msg, icon);
}

/* ================= LOAD SITES ================= */
function fetchUserSites(id){
    const userRow = document.querySelector(`.user-row[data-id="${id}"]`);

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

    document.getElementById('sitesTable').innerHTML =
        `<tr><td colspan="6">Loading...</td></tr>`;

    return fetch(`${STAFF_BASE}/users/${id}/sites`)
        .then(res => {
            if (!res.ok) throw new Error('Failed to load sites');
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

            allSites = sites;
            syncPublisherOpenReviewBadge(id, allSites);
            applySiteFilters();
            return allSites;
        })
        .catch(() => {
            toast('Failed to load sites','error');
            return [];
        });
}

function refreshSidebarQueueBadges() {
    if (typeof window.refreshAdminQueueBadges === 'function') {
        window.refreshAdminQueueBadges();
    }
}

function syncPublisherOpenReviewBadge(publisherId, sites) {
    const row = document.querySelector(`.user-row[data-id="${publisherId}"]`);
    if (!row) return;

    const cell = row.children[3];
    if (!cell) return;

    const openCount = (sites || []).filter(s => !!s.needs_review).length;
    const totalBadge = cell.querySelector('.badge.bg-secondary');
    const totalHtml = totalBadge
        ? totalBadge.outerHTML
        : `<span class="badge rounded-pill bg-secondary ms-1" title="Total sites">${(sites || []).length} total</span>`;

    const newBadge = openCount > 0
        ? `<span class="badge rounded-pill text-bg-warning" title="Sites waiting for admin decision">${openCount} new</span> `
        : '';

    cell.innerHTML = `${newBadge}${totalHtml}`;
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
    if(!site) return;

    Swal.fire({
        title: 'Edit Site',
        width: 550,
        showCancelButton: true,
        confirmButtonText: 'Update',
        html: `
            <div style="text-align: left;">
                <label style="font-weight:600; margin-bottom:5px; display:block;">Site Name</label>
                <input id="swal-site_name" class="swal2-input" value="${escapeHtml(site.site_name ?? '')}" placeholder="Site Name">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Site URL</label>
                <input id="swal-site_url" class="swal2-input" value="${escapeHtml(site.site_url ?? '')}" placeholder="Site URL">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Site Image (Upload)</label>
                <input type="file" id="swal-site_image" class="swal2-file" accept="image/*">
                <div id="imagePreviewContainer" style="margin-top:10px; text-align:center;">
                    ${site.site_image ? `<img id="imagePreview" src="/storage/${site.site_image}" style="max-width:100px; max-height:80px; border-radius:6px; border:1px solid #ddd; padding:3px;">` : '<span style="font-size:12px; color:#888;">No image uploaded</span>'}
                </div>
                <small class="text-muted" style="display:block; margin-top:5px;">Leave empty to keep current image</small>
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">DA (Domain Authority)</label>
                <input id="swal-da" class="swal2-input" type="number" value="${site.da ?? ''}" placeholder="0-100" min="0" max="100" step="1">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">DR (Domain Rating)</label>
                <input id="swal-dr" class="swal2-input" type="number" value="${site.dr ?? ''}" placeholder="0-100" min="0" max="100" step="1">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Traffic (monthly visitors)</label>
                <input id="swal-traffic" class="swal2-input" type="number" value="${site.traffic ?? ''}" placeholder="e.g. 1500000" min="0" max="4294967295" step="1" inputmode="numeric">
            </div>
        `,
        didOpen: () => {
            // Preview new image when selected
            const fileInput = document.getElementById('swal-site_image');
            const previewContainer = document.getElementById('imagePreviewContainer');
            
            if(fileInput && previewContainer) {
                fileInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if(file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewContainer.innerHTML = `<img src="${e.target.result}" style="max-width:100px; max-height:80px; border-radius:6px; border:1px solid #ddd; padding:3px;">`;
                        };
                        reader.readAsDataURL(file);
                    } else if('${site.site_image}') {
                        previewContainer.innerHTML = `<img src="/storage/${site.site_image}" style="max-width:100px; max-height:80px; border-radius:6px; border:1px solid #ddd; padding:3px;">`;
                    } else {
                        previewContainer.innerHTML = '<span style="font-size:12px; color:#888;">No image uploaded</span>';
                    }
                });
            }
        },
        preConfirm: async () => {
            let site_url = document.getElementById('swal-site_url').value.trim();
            let domain = '';

            try {
                domain = new URL(site_url).hostname.replace('www.', '');
            } catch {
                domain = site_url.replace(/^(https?:\/\/)?(www\.)?/, '').split('/')[0];
            }

            const fileInput = document.getElementById('swal-site_image');
            const file = fileInput.files[0];
            
            // If there's a file, upload it first
            if(file) {
                const uploadFormData = new FormData();
                uploadFormData.append('site_image', file);
                uploadFormData.append('_token', '{{ csrf_token() }}');
                
                try {
                    const uploadResponse = await fetch(`${STAFF_BASE}/sites/${siteId}/upload-image`, {
                        method: 'POST',
                        body: uploadFormData
                    });
                    
                    const uploadResult = await uploadResponse.json();
                    
                    if(!uploadResponse.ok) {
                        Swal.showValidationMessage(uploadResult.message || 'Image upload failed');
                        return false;
                    }
                    
                    // Return all data including the uploaded image path
                    return {
                        site_name: document.getElementById('swal-site_name').value,
                        site_url: site_url,
                        domain: domain,
                        site_image: uploadResult.image_path,
                        da: document.getElementById('swal-da').value,
                        dr: document.getElementById('swal-dr').value,
                        traffic: document.getElementById('swal-traffic').value,
                    };
                } catch(error) {
                    Swal.showValidationMessage('Error uploading image: ' + error.message);
                    return false;
                }
            } else {
                // No new image, just return existing data without changing image
                return {
                    site_name: document.getElementById('swal-site_name').value,
                    site_url: site_url,
                    domain: domain,
                    site_image: null, // Will not update image on server
                    da: document.getElementById('swal-da').value,
                    dr: document.getElementById('swal-dr').value,
                    traffic: document.getElementById('swal-traffic').value,
                };
            }
        }
    }).then(async (result) => {
        if(!result.isConfirmed) return;
        await submitSiteUpdate(siteId, result.value);
    });
}

async function submitSiteUpdate(siteId, updateData) {
    try {
        const response = await fetch(`${STAFF_BASE}/sites/${siteId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'X-HTTP-Method-Override': 'PUT',
                'Accept': 'application/json',
            },
            body: JSON.stringify(updateData)
        });

        const data = await response.json();

        if (response.ok) {
            toast('Updated successfully');
            if (data.email_sent) {
                toast('Email notification sent to publisher', 'info');
            }
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            // Ensure Back stays clickable after image upload Swal closes.
            document.body.classList.remove('swal2-shown', 'swal2-height-auto');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        } else {
            toast(data.message || 'Update failed', 'error');
        }
    } catch (error) {
        toast('Update failed: ' + error.message, 'error');
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
        const opening = !row.classList.contains('is-open');
        document.querySelectorAll('#sitesTable .admin-expand-row.is-open').forEach(function (openRow) {
            if (openRow !== row) {
                openRow.classList.remove('is-open');
            }
        });
        row.classList.toggle('is-open', opening);
        const label = e.target.closest('.toggle-site-details');
        if (label) {
            label.innerHTML = opening
                ? '<i class="fa fa-chevron-up me-2"></i>Hide details'
                : '<i class="fa fa-chevron-down me-2"></i>Details';
        }
        return;
    }

    /* EDIT - Using new file upload method */
    if(e.target.closest('.edit-site')){
        let id = e.target.closest('button').dataset.id;
        editSiteWithImage(id);
    }

    /* DELETE */
    if(e.target.closest('.delete-site')){
        let id = e.target.closest('button').dataset.id;
        let site = allSites.find(s => s.id == id);

        Swal.fire({
            title:'Delete this site?',
            text: `Are you sure you want to delete "${site?.site_name}"?`,
            icon:'warning',
            showCancelButton:true,
            confirmButtonText:'Delete',
            customClass: { confirmButton: 'slb-swal-danger' }
        }).then(result => {
            if(!result.isConfirmed) return;

            fetch(`${STAFF_BASE}/sites/${id}`, {
                method:'DELETE',
                headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}
            }).then(() => {
                toast('Deleted successfully');
                afterSiteDecision();
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

        Swal.fire({
            title: activating ? 'Activate Site?' : 'Deactivate Site?',
            text: needsReason
                ? 'Explain why this listing is being deactivated. The publisher will see this reason in email and notifications.'
                : 'Are you sure you want to activate this site?',
            icon: 'question',
            input: needsReason ? 'textarea' : undefined,
            inputLabel: needsReason ? 'Reason for the publisher' : undefined,
            inputPlaceholder: needsReason ? 'Reason (min. 10 characters)' : undefined,
            inputAttributes: needsReason ? { 'aria-label': 'Deactivation reason', maxlength: '1000' } : undefined,
            showCancelButton: true,
            confirmButtonText: activating ? 'Yes, activate' : 'Yes, deactivate',
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

            const payload = { active: activating ? 1 : 0 };
            if (needsReason) {
                const reason = String(result.value || '').trim();
                if (reason.length < 10) {
                    toast('A deactivation reason is required (min. 10 characters).', 'error');
                    return;
                }
                payload.reason = reason;
            }

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
                if(data.email_sent) {
                    toast('Email notification sent to publisher', 'info');
                }
                afterSiteDecision();
            })
            .catch((error) => {
                toast(error.message || `Failed to ${newStatus} site`, 'error');
            });
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
    if(!enrichBtn && !shotBtn) return;

    const btn = enrichBtn || shotBtn;
    const id = btn.dataset.id;
    const url = enrichBtn ? `${STAFF_BASE}/sites/${id}/enrich` : `${STAFF_BASE}/sites/${id}/refresh-screenshot`;
    btn.disabled = true;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ sync: true }),
        });
        const data = await res.json();
        toast(data.message || (data.success ? 'Done' : 'Failed'), data.success ? 'success' : 'error');
        const userId = sessionStorage.getItem('selected_user');
        if (userId && data.success) fetchUserSites(userId);
    } catch (err) {
        toast('Enrichment request failed', 'error');
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
function sitePreviewPaths(site) {
    // Match Site model accessors: thumb → screenshot → uploaded site_image
    const thumb = site.screenshot_thumb_path || null;
    const full = site.screenshot_path || site.site_image || null;
    const preview = thumb || full || site.site_image || null;
    return {
        thumb: preview ? `/storage/${escapeHtml(preview)}` : null,
        full: full ? `/storage/${escapeHtml(full)}` : (preview ? `/storage/${escapeHtml(preview)}` : null),
    };
}

function sitePreviewHtml(site) {
    const paths = sitePreviewPaths(site);
    if (!paths.thumb) {
        return `<span class="site-row-preview is-empty" aria-label="No preview"><i class="fa fa-image" aria-hidden="true"></i></span>`;
    }

    const name = escapeHtml(site.site_name || 'Site');
    const zoomAttr = paths.full ? ` data-zoom-src="${paths.full}" tabindex="0"` : '';

    return `
        <span class="site-row-preview"
              role="img"
              aria-label="${name} preview"${zoomAttr}>
            <img src="${paths.thumb}"
                 alt="${name} preview"
                 loading="lazy"
                 onerror="this.onerror=null; this.parentElement.classList.add('is-empty'); this.parentElement.removeAttribute('data-zoom-src'); this.parentElement.removeAttribute('tabindex'); this.parentElement.innerHTML='<i class=\\'fa fa-image\\' aria-hidden=\\'true\\'></i>';">
        </span>
    `;
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

            // Publisher-style 16:10 preview + site identity
            let siteInfoHtml = `
                <div class="site-info-cell admin-site-info-stack">
                    ${sitePreviewHtml(site)}
                    <div class="site-details">
                        <div class="site-name">
                            ${escapeHtml(site.site_name ?? '-')}
                            ${reviewBadge}
                            ${awaitingBadge}
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

            const editItem = IS_MARKETING_EDITOR
                ? `<li><a class="dropdown-item" href="${STAFF_BASE}/sites/${site.id}/edit"><i class="fa fa-edit me-2"></i>Edit</a></li>`
                : `<li><button type="button" class="dropdown-item edit-site" data-id="${site.id}"><i class="fa fa-edit me-2"></i>Edit</button></li>`;

            const deleteItem = canDeleteSiteRow(site)
                ? `<li><button type="button" class="dropdown-item text-danger delete-site" data-id="${site.id}"><i class="fa fa-trash me-2"></i>Delete</button></li>`
                : '';

            // Always offer Deactivate after Activate for marketing/admin (toggle by live flag).
            const activeItem = CAN_TOGGLE_ACTIVE
                ? (isActive
                    ? `<li><button type="button" class="dropdown-item toggle-active" data-id="${site.id}" data-status="0"><i class="fa fa-pause me-2"></i>Deactivate</button></li>`
                    : `<li><button type="button" class="dropdown-item toggle-active" data-id="${site.id}" data-status="1"><i class="fa fa-play me-2"></i>Activate</button></li>`)
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
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item enrich-site" data-id="${site.id}"><i class="fa fa-sync me-2"></i>Enrich</button></li>
                        <li><button type="button" class="dropdown-item refresh-screenshot" data-id="${site.id}"><i class="fa fa-camera me-2"></i>Shot</button></li>
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
                                    <div class="col-md-4"><strong>Screenshot</strong><div>${paths.thumb ? `<div class="site-preview-detail"><img src="${paths.thumb}" loading="lazy" alt="Site preview"></div>` : '—'}</div></div>
                                    ${site.enrichment_error ? `<div class="col-12"><strong>Last scan error</strong><div class="text-danger small slb-text-break">${escapeHtml(site.enrichment_error)}</div></div>` : ''}
                                    <div class="col-md-4"><strong>Countries</strong><div>${(site.countries && site.countries.length ? site.countries : [site.country]).filter(Boolean).map(c => String(c).toUpperCase()).join(', ') || '-'}</div></div>
                                    <div class="col-md-4"><strong>Languages</strong><div>${(site.languages && site.languages.length ? site.languages : [site.language]).filter(Boolean).map(l => String(l).toUpperCase()).join(', ') || '-'}</div></div>
                                    <div class="col-md-4"><strong>Category</strong><div>${escapeHtml(site.category ?? '-')}</div></div>
                                    <div class="col-md-4"><strong>Link Type</strong><div>${site.link_type ?? '-'}</div></div>
                                    <div class="col-md-4"><strong>Sponsored</strong><div>${site.sponsored ? 'Yes':'No'}</div></div>
                                    <div class="col-md-4"><strong>Price</strong><div>€${site.price ?? '-'}</div></div>
                                    <div class="col-12"><strong>Description</strong><div class="slb-text-break">${escapeHtml(site.description ?? '-')}</div></div>
                                    ${site.site_image ? `<div class="col-12"><strong>Site Image</strong><div class="site-preview-detail"><img src="/storage/${escapeHtml(site.site_image)}" alt="Site image" loading="lazy" onerror="this.style.display='none'"></div></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        });
    }

    document.getElementById('sitesTable').innerHTML = html;

    if (pendingHighlightSiteId) {
        const highlightId = String(pendingHighlightSiteId);
        pendingHighlightSiteId = null;
        const row = document.querySelector(`[data-site-row="${highlightId}"]`);
        if (row) {
            row.classList.add('site-highlight-row');
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            const details = document.getElementById(`details-${highlightId}`);
            if (details) {
                details.classList.remove('d-none');
            }
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
    document.body.classList.remove('swal2-shown', 'swal2-height-auto');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
});

/* ================= SEARCH ================= */
document.getElementById('userSearch').addEventListener('keyup', function(){
    let val = this.value.toLowerCase();
    document.querySelectorAll('#usersTable tr').forEach(r=>{
        r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

document.getElementById('siteSearch').addEventListener('keyup', function(){
    applySiteFilters();
});

document.getElementById('sitesNeedsReviewOnly')?.addEventListener('change', function(){
    applySiteFilters();
});

/* ================= MANAGE MENU: keep table from clipping scrollable panel ================= */
function syncManageOpenState() {
    document.querySelectorAll('.admin-table-fit').forEach((wrap) => {
        const open = !!wrap.querySelector('.admin-manage-dropdown .dropdown-menu.show');
        wrap.classList.toggle('is-manage-open', open);
    });
}

document.addEventListener('show.bs.dropdown', function (e) {
    const dropdown = e.target.closest?.('.admin-manage-dropdown');
    if (!dropdown) return;
    const fit = dropdown.closest('.admin-table-fit');
    if (fit) fit.classList.add('is-manage-open');
});

document.addEventListener('shown.bs.dropdown', function (e) {
    const dropdown = e.target.closest?.('.admin-manage-dropdown');
    if (!dropdown) return;
    const menu = dropdown.querySelector('.dropdown-menu');
    if (menu) {
        // Keep the active option list scrollable inside the panel.
        menu.scrollTop = 0;
    }
    syncManageOpenState();
});

document.addEventListener('hidden.bs.dropdown', function (e) {
    if (!e.target.closest?.('.admin-manage-dropdown')) return;
    syncManageOpenState();
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
                if (IS_MARKETING_EDITOR) {
                    window.location.href = `${STAFF_BASE}/sites/${editSiteId}/edit`;
                    return;
                }
                editSiteWithImage(editSiteId);
                // Drop one-shot edit params so refresh doesn't reopen the modal.
                params.delete('edit_site');
                const next = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
                window.history.replaceState({}, '', next);
            }
        });
        return;
    }

    let id = sessionStorage.getItem('selected_user');
    if(id) {
        revealAllPublisherSites();
        fetchUserSites(id);
    }
});
</script>

@endsection