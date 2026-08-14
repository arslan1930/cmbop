@extends('marketing.layouts.app')

@section('title', 'My task history')

@section('content')
<div class="container-fluid">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">My task history</h1>
            <p class="text-muted mb-0">Seed, edit, activate, deactivate, assign, delete, image, metrics, and bulk updates. Append-only and permanent.</p>
        </div>
        <a href="{{ route('marketing.dashboard') }}" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3" data-history-filters>
        <div class="card-body py-3">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="form-label small mb-1" for="history-q">Search</label>
                    <input id="history-q" type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search subject, details, or task type" title="Results update as you type" autocomplete="off" enterkeyhint="search" data-slb-live-search="form">
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <label class="form-label small mb-1" for="history-action">Task type</label>
                    <select id="history-action" name="action" class="form-select form-select-sm">
                        <option value="">All task types</option>
                        @foreach($actions as $action)
                            <option value="{{ $action }}" @selected($selectedAction === $action)>
                                {{ marketing_task_label($action) }} ({{ (int) ($actionCounts[$action] ?? 0) }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1" for="history-from">From</label>
                    <input id="history-from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1" for="history-to">To</label>
                    <input id="history-to" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-lg-auto d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" type="submit">Apply filters</button>
                    @if(!empty($filtersActive))
                        <a href="{{ route('marketing.history') }}" class="btn btn-sm btn-outline-secondary">Reset filters</a>
                    @endif
                </div>
            </div>
        </div>
    </form>
    @if(!empty($dateErrors))
        <div class="alert alert-warning border-0 py-2" data-history-date-error>
            {{ implode(' ', $dateErrors) }}
        </div>
    @endif
    @if($logs->count() > 0)
        <p class="small text-muted mb-2" data-history-count>Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of {{ $logs->total() }} {{ \Illuminate\Support\Str::plural('task', $logs->total()) }}</p>
    @elseif($logs->total() > 0)
        <p class="small text-muted mb-2" data-history-count>{{ $logs->total() }} {{ \Illuminate\Support\Str::plural('task', $logs->total()) }}</p>
    @elseif(!empty($filtersActive))
        <p class="small text-muted mb-2" data-history-count>0 tasks match these filters</p>
    @endif

    <div class="card border-0 shadow-sm">
        @include('marketing.partials.history-table', ['logs' => $logs, 'filtersActive' => $filtersActive ?? false])
        <div class="p-3">
            {{ $logs->links() }}
        </div>
    </div>

</div>
@endsection
