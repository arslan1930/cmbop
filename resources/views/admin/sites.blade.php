@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">

    <h4 class="mb-4 fw-bold">Sites Management</h4>

    @if(!empty($unverifiedFilter))
        <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Unverified sites queue</strong>
                <span class="ms-1">Showing publishers who still have sites waiting for verification.</span>
            </div>
            <a href="{{ route('admin.sites.index') }}" class="btn btn-sm btn-outline-dark">Show all publishers</a>
        </div>
    @endif

    <!-- ================= USERS TABLE ================= -->
    <div id="usersSection">

        <div class="mb-2" style="max-width: 250px;">
            <input type="text" id="userSearch" class="form-control form-control-sm" placeholder="Search users...">
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">
                {{ !empty($unverifiedFilter) ? 'Publishers with unverified sites' : 'Users' }}
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Sites</th>
                            <th width="120">Action</th>
                        </tr>
                    </thead>

                    <tbody id="usersTable">
                    @forelse($users as $index => $user)
                        <tr class="user-row" data-id="{{ $user->id }}" style="height:60px;">
                            <td>{{ $users->firstItem() + $index }}</td>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                <span class="badge rounded-pill bg-danger" title="Unverified sites">
                                    {{ $user->unverified_sites_count ?? $user->sites->where('verified', 0)->count() }} unverified
                                </span>
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

        <div class="mb-2" style="max-width: 250px;">
            <input type="text" id="siteSearch" class="form-control form-control-sm" placeholder="Search sites...">
        </div>

        <div class="card shadow-sm border-0">

            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Site Information</th>
                            <th>Traffic</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th width="220">Actions</th>
                        </tr>
                    </thead>

                    <tbody id="sitesTable"></tbody>

                 </table>
            </div>

        </div>

    </div>

</div>

<style>
.pulse-dot {
    display:inline-block;
    width:8px;
    height:8px;
    border-radius:50%;
    margin-right:6px;
}
.pulse-green { background:#28a745; animation:pulse-green 1.5s infinite; }
.pulse-red { background:#dc3545; animation:pulse-red 1.5s infinite; }

@keyframes pulse-green {
    0% { box-shadow:0 0 0 0 rgba(40,167,69,0.7);}
    70% { box-shadow:0 0 0 6px rgba(40,167,69,0);}
    100% { box-shadow:0 0 0 0 rgba(40,167,69,0);}
}

@keyframes pulse-red {
    0% { box-shadow:0 0 0 0 rgba(220,53,69,0.7);}
    70% { box-shadow:0 0 0 6px rgba(220,53,69,0);}
    100% { box-shadow:0 0 0 0 rgba(220,53,69,0);}
}

.user-row td {
    padding-top: 14px !important;
    padding-bottom: 14px !important;
}

.btn-action-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.btn-action-group .row-1 {
    display: flex;
    gap: 5px;
    justify-content: center;
}

.btn-action-group .row-2 {
    display: flex;
    gap: 5px;
    justify-content: center;
}

/* Site info column styling */
.site-info-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.site-thumbnail {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid #e0e0e0;
    background: #f8f9fa;
}

.site-details {
    flex: 1;
}

.site-name {
    font-weight: 600;
    font-size: 14px;
    color: #333;
    margin-bottom: 4px;
}

.site-url {
    font-size: 12px;
    color: #6c757d;
    text-decoration: none;
    word-break: break-all;
}

.site-url:hover {
    color: #0d6efd;
    text-decoration: underline;
}

body.layout-dark .site-name {
    color: #eee;
}

body.layout-dark .site-url {
    color: #aaa;
}

body.layout-dark .site-thumbnail {
    border-color: #444;
    background: #2d2d3f;
}
</style>

<script>
const CAN_DELETE_SITES = @json(auth()->user()->isAdmin());
let allSites = [];

/* ================= TOAST ================= */
function toast(msg, icon='success'){
    Swal.fire({
        toast:true,
        position:'top-end',
        icon:icon,
        title:msg,
        showConfirmButton:false,
        timer:2000
    });
}

/* ================= LOAD SITES ================= */
function fetchUserSites(id){

    let userRow = document.querySelector(`.user-row[data-id="${id}"]`);
    if(!userRow) return;

    document.getElementById('siteUserName').innerText =
        userRow.children[1].innerText + " websites";

    document.getElementById('siteUserEmail').innerText =
        userRow.children[2].innerText;

    document.getElementById('usersSection').classList.add('d-none');
    document.getElementById('sitesSection').classList.remove('d-none');

    document.getElementById('sitesTable').innerHTML =
        `<tr><td colspan="6">Loading...</td></tr>`;

    fetch(`/admin/users/${id}/sites`)
        .then(res => res.json())
        .then(data => {
            allSites = data || [];
            renderSites(allSites);
        })
        .catch(() => toast('Failed to load sites','error'));
}

/* ================= EDIT WITH FILE UPLOAD ================= */
function editSiteWithImage(siteId) {
    let site = allSites.find(s => s.id == siteId);
    if(!site) return;

    // Create a form data for file upload
    const formData = new FormData();
    
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
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Traffic</label>
                <input id="swal-traffic" class="swal2-input" type="number" value="${site.traffic ?? ''}" placeholder="Monthly visitors">
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
                    const uploadResponse = await fetch(`/admin/sites/${siteId}/upload-image`, {
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
        
        // Update site data
        const updateData = result.value;
        
        try {
            const response = await fetch(`/admin/sites/${siteId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-HTTP-Method-Override': 'PUT'
                },
                body: JSON.stringify(updateData)
            });
            
            const data = await response.json();
            
            if(response.ok) {
                toast('Updated successfully');
                if(data.email_sent) {
                    toast('Email notification sent to publisher', 'info');
                }
                fetchUserSites(sessionStorage.getItem('selected_user'));
            } else {
                toast(data.message || 'Update failed', 'error');
            }
        } catch(error) {
            toast('Update failed: ' + error.message, 'error');
        }
    });
}

/* ================= EVENTS ================= */
document.addEventListener('click', function(e){

    const btn = e.target.closest('.select-user');
    if(btn){
        let id = btn.dataset.id;
        sessionStorage.setItem('selected_user', id);
        fetchUserSites(id);
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
            confirmButtonColor:'#d33'
        }).then(result => {
            if(!result.isConfirmed) return;

            fetch(`/admin/sites/${id}`, {
                method:'DELETE',
                headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}
            }).then(() => {
                toast('Deleted successfully');
                fetchUserSites(sessionStorage.getItem('selected_user'));
            });
        });
    }

    /* TOGGLE ACTIVE */
    if(e.target.closest('.toggle-active')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;
        let newStatus = status == 1 ? 'activate' : 'deactivate';

        Swal.fire({
            title: `${newStatus === 'activate' ? 'Activate' : 'Deactivate'} Site?`,
            text: `Are you sure you want to ${newStatus} this site?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `Yes, ${newStatus}`,
        }).then(result => {
            if(!result.isConfirmed) return;

            fetch(`/admin/sites/${id}/active`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN':'{{ csrf_token() }}'
                },
                body: JSON.stringify({active: status})
            })
            .then(res => res.json())
            .then(data => {
                toast(`Site ${newStatus}d successfully`);
                if(data.email_sent) {
                    toast(`Email notification sent to publisher`, 'info');
                }
                fetchUserSites(sessionStorage.getItem('selected_user'));
            });
        });
    }

    /* TOGGLE VERIFY */
    if(e.target.closest('.toggle-verify')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;
        let newStatus = status == 1 ? 'verify' : 'unverify';

        Swal.fire({
            title: `${newStatus === 'verify' ? 'Verify' : 'Unverify'} Site?`,
            text: `Are you sure you want to ${newStatus} this site?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `Yes, ${newStatus}`,
        }).then(result => {
            if(!result.isConfirmed) return;

            fetch(`/admin/sites/${id}/verify`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN':'{{ csrf_token() }}'
                },
                body: JSON.stringify({verified: status})
            })
            .then(res => res.json())
            .then(data => {
                toast(`Site ${newStatus}d successfully`);
                if(data.email_sent) {
                    toast(`Email notification sent to publisher`, 'info');
                }
                fetchUserSites(sessionStorage.getItem('selected_user'));
            });
        });
    }
});

/* ================= HELPER ================= */
function escapeHtml(str) {
    if(!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if(m === '&') return '&amp;';
        if(m === '<') return '&lt;';
        if(m === '>') return '&gt;';
        return m;
    });
}

/* ================= RENDER ================= */
function renderSites(data){

    data = [...(data || [])].sort((a,b) => (b.id || 0) - (a.id || 0));

    let html = '';

    if(!data.length){
        html = `<tr><td colspan="6" class="text-center text-muted">No sites found</td></tr>`;
    } else {

        data.forEach((site,i) => {

            // Get image URL or placeholder
            let imageUrl = site.site_image ? `/storage/${escapeHtml(site.site_image)}` : null;
            let firstLetter = (site.site_name || 'S').charAt(0).toUpperCase();
            
            // Create image HTML with fallback
            let imageHtml = imageUrl 
                ? `<img src="${imageUrl}" class="site-thumbnail" alt="${escapeHtml(site.site_name)}" onerror="this.onerror=null; this.src=''; this.style.display='none'; this.parentElement.querySelector('.thumbnail-fallback').style.display='flex';">`
                : '';
            
            // Create combined site info column with image, name and URL
            let siteInfoHtml = `
                <div class="site-info-cell">
                    ${imageUrl ? imageHtml : ''}
                    <div class="site-details">
                        <div class="site-name">${escapeHtml(site.site_name ?? '-')}</div>
                        <a href="${escapeHtml(site.site_url ?? '#')}" target="_blank" class="site-url" title="${escapeHtml(site.site_url ?? '')}">
                            ${escapeHtml(site.site_url ?? '-')}
                        </a>
                    </div>
                </div>
            `;

            html += `
                <tr>
                    <td>${i+1}</td>
                    <td>${siteInfoHtml}</td>
                    <td>${site.traffic ?? '-'}</td>
                    <td>€${site.price ?? '-'}</td>
                    <td>
                        ${site.active
                            ? '<span class="pulse-dot pulse-green"></span>Active'
                            : '<span class="pulse-dot pulse-red"></span>Inactive'}
                    </td>
                    <td>
                        <div class="btn-action-group">
                            <div class="row-1">
                                <button class="btn btn-sm btn-outline-primary edit-site" data-id="${site.id}">
                                    <i class="fa fa-edit"></i> Edit
                                </button>
                                ${CAN_DELETE_SITES ? `<button class="btn btn-sm btn-outline-danger delete-site" data-id="${site.id}">
                                    <i class="fa fa-trash"></i> Delete
                                </button>` : ''}
                            </div>
                            <div class="row-2">
                                ${site.active
                                    ? `<button class="btn btn-sm btn-secondary toggle-active" data-id="${site.id}" data-status="0">
                                        <i class="fa fa-pause"></i> Deactivate
                                       </button>`
                                    : `<button class="btn btn-sm btn-success toggle-active" data-id="${site.id}" data-status="1">
                                        <i class="fa fa-play"></i> Activate
                                       </button>`}
                                ${site.verified
                                    ? `<button class="btn btn-sm btn-warning toggle-verify" data-id="${site.id}" data-status="0">
                                        <i class="fa fa-times"></i> Unverify
                                       </button>`
                                    : `<button class="btn btn-sm btn-primary toggle-verify" data-id="${site.id}" data-status="1">
                                        <i class="fa fa-check"></i> Verify
                                       </button>`}
                            </div>
                        </div>
                    </td>
                </tr>

                <tr id="details-${site.id}" class="d-none">
                    <td colspan="6">
                        <div class="p-3 border rounded bg-white shadow-sm">
                            <div class="row g-3">
                                <div class="col-md-4"><strong>Domain</strong><div>${escapeHtml(site.domain ?? '-')}</div></div>
                                <div class="col-md-4"><strong>DA/DR</strong><div>${site.da ?? '-'} / ${site.dr ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Traffic</strong><div>${site.traffic ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Country</strong><div>${site.country ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Language</strong><div>${site.language ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Category</strong><div>${escapeHtml(site.category ?? '-')}</div></div>
                                <div class="col-md-4"><strong>Link Type</strong><div>${site.link_type ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Sponsored</strong><div>${site.sponsored ? 'Yes':'No'}</div></div>
                                <div class="col-md-4"><strong>Price</strong><div>€${site.price ?? '-'}</div></div>
                                <div class="col-12"><strong>Description</strong><div>${escapeHtml(site.description ?? '-')}</div></div>
                                ${site.site_image ? `<div class="col-12"><strong>Site Image</strong><div><img src="/storage/${escapeHtml(site.site_image)}" style="max-width:200px; max-height:120px; border-radius:8px; margin-top:5px;" onerror="this.style.display='none'"></div></div>` : ''}
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        });
    }

    document.getElementById('sitesTable').innerHTML = html;
}

/* ================= BACK ================= */
document.getElementById('backBtn').addEventListener('click', function(){
    document.getElementById('sitesSection').classList.add('d-none');
    document.getElementById('usersSection').classList.remove('d-none');
    sessionStorage.removeItem('selected_user');
});

/* ================= SEARCH ================= */
document.getElementById('userSearch').addEventListener('keyup', function(){
    let val = this.value.toLowerCase();
    document.querySelectorAll('#usersTable tr').forEach(r=>{
        r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});

document.getElementById('siteSearch').addEventListener('keyup', function(){ 
    let val = this.value.toLowerCase();
    let filtered = allSites.filter(s =>
        (s.site_name||'').toLowerCase().includes(val) ||
        (s.domain||'').toLowerCase().includes(val) ||
        (s.site_url||'').toLowerCase().includes(val)
    );
    renderSites(filtered);  
});

/* ================= RESTORE ================= */
window.addEventListener('DOMContentLoaded',()=>{
    let id = sessionStorage.getItem('selected_user');
    if(id) fetchUserSites(id);
});
</script>

@endsection