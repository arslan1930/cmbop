@extends('marketing.layouts.app')

@section('title', 'My task history')

@section('content')
<div class="container-fluid">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">My task history</h1>
            <p class="text-muted mb-0">Every marketing action you’ve performed — seeding, edits, activations, assigns, and bulk updates. Append-only and permanent.</p>
        </div>
        <a href="{{ route('marketing.dashboard') }}" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="search" name="q" value="{{ $qFilter }}" class="form-control form-control-sm" placeholder="Search description, subject, or action" title="Results update as you type" autocomplete="off" enterkeyhint="search" data-slb-live-search="form">
        </div>
        <div class="col-md-3">
            <select name="action" class="form-select form-select-sm">
                <option value="">All task types</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected($actionFilter === $action)>
                        {{ marketing_task_label($action) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" name="from" value="{{ $fromFilter }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <input type="date" name="to" value="{{ $toFilter }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button class="btn btn-sm btn-primary flex-grow-1" type="submit">Filter</button>
        </div>
    </form>
    @if(!empty($dateErrors))
        <div class="alert alert-warning border-0 py-2" data-history-date-error>
            {{ implode(' ', $dateErrors) }}
        </div>
    @endif
    @if(!empty($filtersActive))
        <div class="mb-3">
            <a href="{{ route('marketing.history') }}" class="small">Reset filters</a>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        @include('marketing.partials.history-table', ['logs' => $logs, 'filtersActive' => $filtersActive ?? false])
        <div class="p-3">
            {{ $logs->links() }}
        </div>
    </div>

</div>
@endsection
