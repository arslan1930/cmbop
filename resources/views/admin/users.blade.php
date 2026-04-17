@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    <h1 class="h3 mb-4">User Management</h1>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

<style>
.modern-table {
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #eee;
    text-align: center;
}

.modern-table th, .modern-table td {
    vertical-align: middle !important;
    text-align: center;
}

.modern-table thead {
    background: #343a40;
    color: #fff;
}

.modern-table tbody tr:hover {
    background: #f7fbff;
}

.expand-row {
    background: #fafafa;
    transition: all 0.3s ease-in-out;
}

.expand-row td {
    padding: 0 !important;
}

.expand-box {
    padding: 0 18px;
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    transition: all 0.3s ease-in-out;
}

.expand-row.expanded .expand-box {
    padding: 18px;
    max-height: 800px;
    opacity: 1;
}

.detail-line {
    margin-bottom: 8px;
    font-size: 14px;
    text-align: left;
}

.detail-line strong {
    color: #555;
}
</style>

<!-- SEARCH -->
<div class="mb-3" style="max-width: 400px;">
    <input type="text" id="userSearch" class="form-control" placeholder="Search users (name, email, company...)">
</div>

<div class="table-responsive">

<table class="table table-striped modern-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Country</th>
            <th>Role</th>
            <th>Joined</th>
            <th width="260">Actions</th>
        </tr>
    </thead>

    <tbody>
    @forelse($users as $index => $user)

    <tr class="main-row" data-id="{{ $user->id }}">

        <!-- ✅ FIX: role id added (NO UI CHANGE) -->
        <input type="hidden" class="role-id" value="{{ $user->active_role_id }}">

        <td>{{ $users->firstItem() + $index }}</td>
        <td>{{ $user->name }}</td>
        <td>{{ $user->email }}</td>
        <td>{{ $user->phone ?? '-' }}</td>
        <td>{{ $user->country ?? '-' }}</td>
        <td>
            <!-- KEEP EXACT SAME UI -->
            <span class="badge bg-primary">
                {{ $user->activeRole() ?? '-' }}
            </span>
        </td>
        <td>{{ $user->created_at ? $user->created_at->format('d M Y') : '-' }}</td>

        <td>
            <button class="btn btn-sm btn-outline-primary action-view" data-id="{{ $user->id }}">
                <i class="fa fa-eye me-1"></i>
                <span class="btn-text">View</span>
            </button>

            <!-- <a href="#" class="btn btn-sm btn-primary">
                <i class="fa fa-edit me-1"></i>
                Edit
            </a> -->

            <form action="#" method="POST" style="display:inline-block;">
                @csrf
                @method('DELETE')
                <button type="button" class="btn btn-sm btn-danger btn-delete">
                    <i class="fa fa-trash me-1"></i>
                    Delete
                </button>
            </form>
        </td>
    </tr>

<tr class="expand-row" id="expand-{{ $user->id }}">
    <td colspan="8">
        <div class="expand-box">

            <div class="row text-start">

                <div class="col-md-4">
                    <strong>User Info:</strong>

                    <div class="detail-line">
                        <strong>Full Name:</strong> {{ $user->name }}
                    </div>

                    <div class="detail-line">
                        <strong>Email:</strong> {{ $user->email }}
                    </div>

                    <div class="detail-line">
                        <strong>Phone:</strong> {{ $user->phone ?? '-' }}
                    </div>
                </div>

                <div class="col-md-4">
                    <strong>Billing Info:</strong>

                    <div class="detail-line">
                        <strong>Company:</strong> 
                        <span class="company-text" data-id="{{ $user->id }}">
                            {{ $user->company_name ?? '-' }}
                        </span>

                        <button class="btn btn-sm btn-link text-primary p-0 ms-2 btn-edit-company" data-id="{{ $user->id }}">
                            Edit
                        </button>
                    </div>

                    <div class="detail-line">
                        <strong>Billing Name:</strong> {{ $user->billing_name ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>VAT Number:</strong> {{ $user->vat_number ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>Country:</strong> {{ $user->country ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>State:</strong> {{ $user->state ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>City:</strong> {{ $user->city ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>Address:</strong> {{ $user->address ?? '-' }}
                    </div>

                    <div class="detail-line">
                        <strong>Postal Code:</strong> {{ $user->postal_code ?? '-' }}
                    </div>
                </div>

                <div class="col-md-4 mt-3 text-start">
                    <strong>Social Profiles:</strong><br>

                    @if($user->facebook)
                        <a href="{{ $user->facebook }}" target="_blank" class="me-2 badge bg-primary">Facebook</a>
                    @endif

                    @if($user->twitter)
                        <a href="{{ $user->twitter }}" target="_blank" class="me-2 badge bg-info">Twitter</a>
                    @endif

                    @if($user->linkedin)
                        <a href="{{ $user->linkedin }}" target="_blank" class="me-2 badge bg-dark">LinkedIn</a>
                    @endif

                    @if(!$user->facebook && !$user->twitter && !$user->linkedin)
                        <span class="text-muted">No social profiles</span>
                    @endif
                </div>

            </div>

            <div class="mt-3 text-start">
                <div class="detail-line">
                    <strong>Joined:</strong> {{ $user->created_at ? $user->created_at->format('d M Y, h:i A') : '-' }}
                </div>

                <div class="detail-line">
                    <strong>Last Updated:</strong> {{ $user->updated_at ? $user->updated_at->format('d M Y, h:i A') : '-' }}
                </div>
            </div>

        </div>
    </td>
</tr>

    @empty
    <tr>
        <td colspan="8" class="text-center text-muted">
            No users found.
        </td>
    </tr>
    @endforelse
    </tbody>
</table>

</div>

<div class="mt-3">
    {{ $users->links() }}
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('click', function(e){

    const viewBtn = e.target.closest('.action-view');
    if(viewBtn){
        e.stopPropagation();

        let id = viewBtn.dataset.id;
        let expandRow = document.getElementById('expand-' + id);

        if(!expandRow) return;

        expandRow.classList.toggle('expanded');

        let icon = viewBtn.querySelector('i');
        let text = viewBtn.querySelector('.btn-text');

        if (expandRow.classList.contains('expanded')) {
            icon.classList.replace('fa-eye', 'fa-eye-slash');
            if(text) text.textContent = 'Hide';
        } else {
            icon.classList.replace('fa-eye-slash', 'fa-eye');
            if(text) text.textContent = 'View';
        }
    }

    const deleteBtn = e.target.closest('.btn-delete');
    if(deleteBtn){
        let form = deleteBtn.closest('form');

        Swal.fire({
            title: 'Are you sure?',
            text: "This user will be deleted permanently!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    }

    const editBtn = e.target.closest('.btn-edit-company');
    if(editBtn){
        let id = editBtn.dataset.id;
        let span = document.querySelector('.company-text[data-id="'+id+'"]');
        let current = span.innerText.trim() === '-' ? '' : span.innerText.trim();

        Swal.fire({
            title: 'Edit Company',
            input: 'text',
            inputValue: current,
            showCancelButton: true,
            confirmButtonText: 'Update'
        }).then((result) => {
            if(result.isConfirmed){

                fetch(`/admin/users/${id}/update-company`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        company_name: result.value
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success){
                        span.innerText = result.value || '-';
                        Swal.fire('Updated!', '', 'success');
                    } else {
                        Swal.fire('Error!', '', 'error');
                    }
                });
            }
        });
    }

});

// SEARCH ONLY (UNCHANGED LOGIC)
document.getElementById('userSearch').addEventListener('keyup', function(){
    let value = this.value.toLowerCase();

    document.querySelectorAll('tbody tr.main-row').forEach(row => {
        let text = row.innerText.toLowerCase();
        let id = row.dataset.id;
        let expandRow = document.getElementById('expand-' + id);

        if(text.includes(value)){
            row.style.display = '';
            if(expandRow) expandRow.style.display = '';
        } else {
            row.style.display = 'none';
            if(expandRow) expandRow.style.display = 'none';
        }
    });
});
</script>

@endsection 