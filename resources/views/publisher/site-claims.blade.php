@extends(\App\Support\PortalShell::layout())

@section('title', 'My ownership claims')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Your ownership claims</h4>
            <p class="text-muted mb-0 small">Track the status of every website you have claimed. We email you after each review.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($claims->isEmpty())
                <div class="text-center text-muted py-5">
                    <i class="fa fa-user-check fa-2x mb-2 d-block"></i>
                    You have not submitted any ownership claims yet.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Website</th>
                                <th>Name match</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Reviewed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($claims as $claim)
                                @php
                                    $statusClass = match($claim->status) {
                                        'approved' => 'bg-success',
                                        'rejected' => 'bg-danger',
                                        default => 'bg-warning text-dark',
                                    };
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $claim->display_name ?? $claim->displayNameFor(auth()->user()) }}</div>
                                        <div class="small text-muted">{{ $claim->display_host ?? $claim->displayHostFor(auth()->user()) }}</div>
                                        @if($claim->status !== 'pending' && filled($claim->admin_notes))
                                            <div class="small text-muted fst-italic">Note: {{ $claim->admin_notes }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($claim->name_matches)
                                            <span class="badge bg-success-subtle text-success border">Matches</span>
                                        @else
                                            <span class="badge bg-warning-subtle text-dark border">Mismatch</span>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $statusClass }}">{{ ucfirst($claim->status) }}</span></td>
                                    <td class="small text-muted">{{ optional($claim->created_at)->diffForHumans() ?: '—' }}</td>
                                    <td class="small text-muted">{{ optional($claim->reviewed_at)->diffForHumans() ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $claims->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
