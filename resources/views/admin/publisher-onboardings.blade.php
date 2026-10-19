@extends(staff_layout())

@section('title', 'Publisher onboarding')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Publisher onboarding</h4>
            <p class="text-muted small mb-0">Staff-created publishers. Invited = waiting to set a password or sign in with Google. Activated = they opened the invite.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.publishers.onboard') }}" class="btn btn-sm btn-primary">Add publisher</a>
            <a href="{{ route('admin.publishers.onboard.bulk') }}" class="btn btn-sm btn-outline-secondary">Bulk CSV</a>
        </div>
    </div>

    <form method="GET" class="mb-3 d-flex gap-2">
        <select name="status" class="form-select form-select-sm" style="max-width: 200px" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="invited" @selected($status === 'invited')>Invited</option>
            <option value="activated" @selected($status === 'activated')>Activated</option>
        </select>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Publisher</th>
                        <th>Status</th>
                        <th>Sites</th>
                        <th>Pending invites</th>
                        <th>Invite sent</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($publishers as $publisher)
                        <tr>
                            <td>
                                <a href="{{ route('admin.users.show', $publisher) }}">{{ $publisher->name }}</a>
                                <div class="small text-muted">{{ $publisher->email }}</div>
                            </td>
                            <td>
                                @if($publisher->staffOnboardingStatus() === 'activated')
                                    <span class="badge text-bg-success">Activated</span>
                                @else
                                    <span class="badge text-bg-warning text-dark">Invited</span>
                                @endif
                            </td>
                            <td>{{ (int) ($publisher->sites_count ?? 0) }}</td>
                            <td>{{ (int) ($publisher->pending_invites_count ?? 0) }}</td>
                            <td>{{ $publisher->staff_invite_sent_at?->format('Y-m-d H:i') ?: '—' }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.publishers.resend', $publisher) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Resend invite</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No staff-created publishers yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $publishers->links() }}</div>
</div>
@endsection
