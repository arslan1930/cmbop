@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Drafts</h1>
            <p class="text-muted mb-0">Saved campaigns stay here until you send or delete them. Sending clones a new campaign and keeps the draft.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> New campaign
            </a>
            <a href="{{ route('admin.audiences.index') }}" class="btn btn-sm btn-outline-primary">
                Audience Inventory
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Subject</th>
                            <th>Audience</th>
                            <th>Updated</th>
                            <th>Author</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($drafts as $draft)
                            <tr>
                                <td class="fw-semibold small">{{ $draft->name ?: '—' }}</td>
                                <td class="small">{{ \Illuminate\Support\Str::limit($draft->subject, 48) }}</td>
                                <td class="small">{{ $draft->audienceLabel() }}</td>
                                <td class="small text-muted">{{ optional($draft->updated_at)->format('M j, g:ia') ?: '—' }}</td>
                                <td class="small">{{ optional($draft->creator)->name ?: '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.campaigns.drafts.edit', $draft) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                    <form method="POST" action="{{ route('admin.campaigns.drafts.destroy', $draft) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-slb-confirm="Delete this draft? This cannot be undone."
                                                data-slb-confirm-title="Delete draft?"
                                                data-slb-confirm-text="Delete"
                                                data-slb-confirm-danger="1">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No drafts yet. Save from Compose to add one.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($drafts->hasPages())
            <div class="card-footer bg-white">{{ $drafts->links() }}</div>
        @endif
    </div>
</div>
@endsection
