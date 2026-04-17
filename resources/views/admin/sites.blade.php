@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">

    <h4 class="mb-4 fw-bold">Sites Management</h4>

    <!-- ================= USERS TABLE ================= -->
    <div id="usersSection">

        <div class="mb-2" style="max-width: 250px;">
            <input type="text" id="userSearch" class="form-control form-control-sm" placeholder="Search users...">
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">
                Users
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
                                <span class="badge rounded-pill bg-danger">
                                    {{ $user->sites->where('verified', 0)->count() }}
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
                            <th>Site</th>
                            <th>Site URL</th>
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
</style>

<script>
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
        `<tr><td colspan="7">Loading...</td></tr>`;

    fetch(`/admin/users/${id}/sites`)
        .then(res => res.json())
        .then(data => {
            allSites = data || [];
            renderSites(allSites);
        })
        .catch(() => toast('Failed to load sites','error'));
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

    /* EDIT */
    if(e.target.closest('.edit-site')){
        let id = e.target.closest('button').dataset.id;
        let site = allSites.find(s => s.id == id);
        if(!site) return;

        Swal.fire({
            title: 'Update Site Details',
            width: 650,
            showCancelButton: true,
            confirmButtonText: 'Update',
            html: `
                <input id="swal-site_name" class="swal2-input" value="${site.site_name ?? ''}">
                <input id="swal-site_url" class="swal2-input" value="${site.site_url ?? ''}">
                <input id="swal-da" class="swal2-input" value="${site.da ?? ''}">
                <input id="swal-dr" class="swal2-input" value="${site.dr ?? ''}">
                <input id="swal-traffic" class="swal2-input" value="${site.traffic ?? ''}">
            `,
            preConfirm: () => {
                let site_url = document.getElementById('swal-site_url').value.trim();
                let domain = '';

                try {
                    domain = new URL(site_url).hostname.replace('www.', '');
                } catch {
                    domain = site_url.replace(/^(https?:\/\/)?(www\.)?/, '').split('/')[0];
                }

                return {
                    site_name: document.getElementById('swal-site_name').value,
                    site_url,
                    domain,
                    da: document.getElementById('swal-da').value,
                    dr: document.getElementById('swal-dr').value,
                    traffic: document.getElementById('swal-traffic').value,
                };
            }
        }).then(result => {
            if(!result.isConfirmed) return;

            fetch(`/admin/sites/${id}`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN':'{{ csrf_token() }}',
                    'X-HTTP-Method-Override':'PUT'
                },
                body: JSON.stringify(result.value)
            })
            .then(() => {
                toast('Updated successfully');
                fetchUserSites(sessionStorage.getItem('selected_user'));
            });
        });
    }

    /* DELETE */
    if(e.target.closest('.delete-site')){
        let id = e.target.closest('button').dataset.id;

        Swal.fire({
            title:'Delete this site?',
            icon:'warning',
            showCancelButton:true,
            confirmButtonText:'Delete'
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

        fetch(`/admin/sites/${id}/active`, {
            method:'POST',
            headers:{
                'Content-Type':'application/json',
                'X-CSRF-TOKEN':'{{ csrf_token() }}'
            },
            body: JSON.stringify({active: status})
        }).then(() => {
            toast('Active status updated');
            fetchUserSites(sessionStorage.getItem('selected_user'));
        });
    }

    /* TOGGLE VERIFY */
    if(e.target.closest('.toggle-verify')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;

        fetch(`/admin/sites/${id}/verify`, {
            method:'POST',
            headers:{
                'Content-Type':'application/json',
                'X-CSRF-TOKEN':'{{ csrf_token() }}'
            },
            body: JSON.stringify({verified: status})
        }).then(() => {
            toast('Verification updated');
            fetchUserSites(sessionStorage.getItem('selected_user'));
        });
    }
});

/* ================= RENDER ================= */
function renderSites(data){

    data = [...(data || [])].sort((a,b) => (b.id || 0) - (a.id || 0));

    let html = '';

    if(!data.length){
        html = `<tr><td colspan="7" class="text-center text-muted">No sites found</td></tr>`;
    } else {

        data.forEach((site,i) => {

            html += `
                <tr>
                    <td>${i+1}</td>
                    <td class="fw-semibold">${site.site_name ?? '-'}</td>
                    <td>${site.site_url ?? '-'}</td>
                    <td>${site.traffic ?? '-'}</td>
                    <td>${site.price ?? '-'}</td>

                    <td>
                        ${site.active
                            ? '<span class="pulse-dot pulse-green"></span>Active'
                            : '<span class="pulse-dot pulse-red"></span>Inactive'}
                    </td>

                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-info view-site" data-id="${site.id}">View</button>
                            <button class="btn btn-sm btn-outline-primary edit-site" data-id="${site.id}">Edit</button>
                        </div>

                        <div class="d-flex gap-1 mt-1">
                            <button class="btn btn-sm btn-outline-danger delete-site" data-id="${site.id}">Delete</button>

                            ${site.active
                                ? `<button class="btn btn-sm btn-secondary toggle-active" data-id="${site.id}" data-status="0">Deactivate</button>`
                                : `<button class="btn btn-sm btn-success toggle-active" data-id="${site.id}" data-status="1">Activate</button>`}

                            ${site.verified
                                ? `<button class="btn btn-sm btn-warning toggle-verify" data-id="${site.id}" data-status="0">Unverify</button>`
                                : `<button class="btn btn-sm btn-primary toggle-verify" data-id="${site.id}" data-status="1">Verify</button>`}
                        </div>
                    </td>
                </tr>

                <tr id="details-${site.id}" class="d-none">
                    <td colspan="7">
                        <div class="p-3 border rounded bg-white shadow-sm">
                            <div class="row g-3">
                                <div class="col-md-4"><strong>Domain</strong><div>${site.domain ?? '-'}</div></div>
                                <div class="col-md-4"><strong>DA/DR</strong><div>${site.da ?? '-'} / ${site.dr ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Traffic</strong><div>${site.traffic ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Country</strong><div>${site.country ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Language</strong><div>${site.language ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Category</strong><div>${site.category ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Link Type</strong><div>${site.link_type ?? '-'}</div></div>
                                <div class="col-md-4"><strong>Sponsored</strong><div>${site.sponsored ? 'Yes':'No'}</div></div>
                                <div class="col-md-4"><strong>Price</strong><div>${site.price ?? '-'}</div></div>
                                <div class="col-12"><strong>Description</strong><div>${site.description ?? '-'}</div></div>
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
        (s.domain||'').toLowerCase().includes(val)
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