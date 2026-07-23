@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Activity History</h1>
            <p class="text-muted mb-0">Every dashboard action with the registered user’s name. History is append-only and cannot be deleted.</p>
        </div>
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" name="user" value="{{ request('user') }}" class="form-control form-control-sm" placeholder="Filter by user name / email">
        </div>
        <div class="col-md-3">
            <select name="action" class="form-select form-select-sm">
                <option value="">All actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button class="btn btn-sm btn-primary flex-grow-1" type="submit">Filter</button>
            <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Subject</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="small text-nowrap">
                                {{ $log->created_at?->format('d M Y') }}<br>
                                <span class="text-muted">{{ $log->created_at?->format('H:i:s') }}</span>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $log->user_name ?? 'System' }}</div>
                                <div class="small text-muted">{{ $log->user_email }}</div>
                            </td>
                            <td>
                                @if($log->role)
                                    <span class="badge bg-secondary text-capitalize">{{ $log->role }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td><code class="small">{{ $log->action }}</code></td>
                            <td class="small">{{ $log->subject_label ?? '—' }}</td>
                            <td class="small">{{ $log->description }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No activity recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">
            {{ $logs->links() }}
        </div>
    </div>

</div>
@endsection
