@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Activity History</h1>
            <p class="text-muted mb-0">Append-only log of actions recorded by ActivityLogger (sites, bulk onboarding, selected money and growth events). History cannot be deleted.</p>
        </div>
        @if(empty($dateErrors) && empty($exportCapped))
            <a href="{{ route('admin.activity-logs.export', $exportQuery ?? []) }}" class="btn btn-outline-primary">Export CSV</a>
        @elseif(!empty($exportCapped))
            <p class="small text-muted mb-0">More than {{ number_format($exportLimit ?? \App\Http\Controllers\Admin\ActivityLogController::EXPORT_LIMIT) }} events match — narrow filters to export.</p>
        @endif
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3 admin-deposits-filter-card admin-deposits-filters admin-orders-filters">
        <div class="card-body py-3">
            <div class="admin-orders-filters__grid">
                <div class="admin-orders-filters__search">
                    <x-slb-search-field
                        name="user"
                        id="logUser"
                        :value="request('user')"
                        placeholder="Filter by user name / email"
                        label="User"
                        label-class="form-label"
                        input-class="form-control"
                    />
                </div>
                <div class="admin-orders-filters__search">
                    <x-slb-search-field
                        name="q"
                        id="logQ"
                        :value="request('q')"
                        placeholder="Search subject, details, or action"
                        label="Search"
                        label-class="form-label"
                        input-class="form-control"
                    />
                </div>
                <div>
                    <label class="form-label" for="logAction">Action</label>
                    <select id="logAction" name="action" class="form-select">
                        <option value="">All actions</option>
                        @foreach($actions as $action)
                            <option value="{{ $action }}" @selected($selectedAction === $action)>
                                {{ activity_action_label($action) }}@if(isset($actionCounts[$action])) ({{ (int) $actionCounts[$action] }})@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="logRole">Role</label>
                    <select id="logRole" name="role" class="form-select">
                        <option value="">All roles</option>
                        @foreach(\App\Http\Controllers\Admin\ActivityLogController::ROLES as $role)
                            <option value="{{ $role }}" @selected($selectedRole === $role)>{{ ucfirst($role) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="logFrom">From</label>
                    <input type="date" id="logFrom" name="from" value="{{ search_text(request('from')) }}" class="form-control">
                </div>
                <div>
                    <label class="form-label" for="logTo">To</label>
                    <input type="date" id="logTo" name="to" value="{{ search_text(request('to')) }}" class="form-control">
                </div>
                @if(!empty($filterUserId))
                    <input type="hidden" name="user_id" value="{{ (int) $filterUserId }}">
                @endif
                <div class="admin-deposits-filters__actions admin-orders-filters__actions">
                    <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Apply filters</button>
                    @if(!empty($filtersActive))
                        <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-outline-secondary">Reset</a>
                    @endif
                    </div>
                </div>
            </div>
        </div>
    </form>
    @if(!empty($filterUserId))
        <div class="alert alert-light border py-2 px-3 small mb-3 d-flex flex-wrap align-items-center gap-2">
            <span>Actor filter:</span>
            <span class="fw-semibold">User #{{ (int) $filterUserId }}</span>
            <a href="{{ route('admin.activity-logs.index', collect($exportQuery ?? [])->except('user_id')->all()) }}" class="ms-auto">Clear actor</a>
        </div>
    @endif
    @if(!empty($dateErrors))
        <div class="alert alert-warning border-0 py-2">
            {{ implode(' ', $dateErrors) }}
        </div>
    @endif
    @if($logs->total() > 0)
        <p class="small text-muted mb-2">Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of {{ $logs->total() }} {{ \Illuminate\Support\Str::plural('event', $logs->total()) }}</p>
    @elseif(!empty($filtersActive))
        <p class="small text-muted mb-2">0 events match these filters</p>
    @endif

    <div class="card border-0 shadow-sm">
        @php
            $historyLookup = \App\Support\AdminActivityDisplay::preload($logs);
        @endphp
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Subject</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $subjectUrl = \App\Support\AdminActivityDisplay::subjectUrl($log, $historyLookup);
                            $reason = \App\Support\AdminActivityDisplay::reason($log);
                            $changeKeys = \App\Support\AdminActivityDisplay::changeKeys($log);
                            $statusChange = \App\Support\AdminActivityDisplay::statusChange($log);
                            $removed = \App\Support\AdminActivityDisplay::isRemoved($log, $historyLookup);
                        @endphp
                        <tr>
                            <td class="small text-nowrap">
                                <div>{{ $log->created_at?->diffForHumans() }}</div>
                                <span class="text-muted">{{ $log->created_at?->format('d M Y H:i') }}</span>
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
                            <td>
                                <div class="fw-semibold">{{ activity_action_label($log->action) }}</div>
                                <code class="small text-muted">{{ activity_action_canonical($log->action) }}</code>
                            </td>
                            <td class="small">
                                @if($subjectUrl)
                                    <a href="{{ $subjectUrl }}">{{ $log->subject_label ?: 'Open' }}</a>
                                @else
                                    {{ $log->subject_label ?: '—' }}
                                @endif
                                @if($removed)
                                    <span class="badge bg-secondary ms-1">Removed</span>
                                @endif
                            </td>
                            <td class="small">
                                <div>{{ $log->description }}</div>
                                @if($reason)
                                    <div class="text-muted mt-1">{{ \App\Support\AdminActivityDisplay::reasonLabel($log) }}: {{ $reason }}</div>
                                @endif
                                @if($changeKeys !== [])
                                    <div class="text-muted mt-1">Changed: {{ implode(', ', $changeKeys) }}</div>
                                @endif
                                @if($statusChange)
                                    <div class="text-muted mt-1">{{ $statusChange }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                @if(!empty($filtersActive))
                                    <div class="mb-2">No events match these filters.</div>
                                    <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-outline-secondary">Reset filters</a>
                                @else
                                    No activity recorded yet.
                                @endif
                            </td>
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
