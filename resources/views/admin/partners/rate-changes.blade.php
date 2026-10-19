@extends(staff_layout())

@section('title', 'Partner rate changes')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Partner rate change queue</h4>
            <p class="text-muted small mb-0">Cuts apply at once. Rises apply at once only if the 35% floor still holds; otherwise they wait here. Open orders keep the frozen publisher amount they were created with. Teqno LTD.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm {{ $status === 'pending' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.partners.rate-changes.index', ['status' => 'pending']) }}">Pending</a>
            <a class="btn btn-sm {{ $status === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.partners.rate-changes.index', ['status' => 'all']) }}">All</a>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Site</th>
                        <th>Old €</th>
                        <th>New €</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($changes as $change)
                        @php
                            $old = (float) data_get($change->old_values, 'publish_eur', 0);
                            $new = (float) data_get($change->new_values, 'publish_eur', 0);
                        @endphp
                        <tr>
                            <td>{{ $change->id }}</td>
                            <td>{{ $change->item?->domain }} <span class="text-muted small">{{ $change->item?->partner?->displayName() }}</span></td>
                            <td>{{ number_format($old, 2) }}</td>
                            <td>{{ number_format($new, 2) }}</td>
                            <td>{{ $change->status }}</td>
                            <td class="text-end">
                                @if($change->status === 'pending')
                                    <form method="POST" action="{{ route('admin.partners.rate-changes.approve', $change) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="raise_slb" value="1">
                                        <button class="btn btn-sm btn-primary" type="submit">Approve &amp; raise SLB</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.partners.rate-changes.approve', $change) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="raise_slb" value="0">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Approve, keep SLB</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.partners.rate-changes.decline', $change) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Decline</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No rate changes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $changes->links() }}</div>
</div>
@endsection
