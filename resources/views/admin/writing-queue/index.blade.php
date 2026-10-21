@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Writing queue',
        'subtitle' => 'SEOLinkBuildings-written articles waiting for assignment, upload, or revision.',
    ])

    <form method="GET" action="{{ route('admin.writing-queue.index') }}" class="d-flex flex-wrap gap-2 mb-3">
        <select name="status" class="form-select" style="max-width:16rem;">
            <option value="">Open writing tasks</option>
            @foreach(['awaiting_writer','assigned','article_submitted','article_revision','article_approved','released_to_publisher'] as $st)
                <option value="{{ $st }}" @selected($status === $st)>{{ $st }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Site</th>
                        <th>Language</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>#{{ $item->order?->order_number }}</td>
                            <td>{{ $item->site_name }}</td>
                            <td>{{ $item->writing_language ?: '—' }}</td>
                            <td class="small">{{ $item->target_url ?: '—' }}</td>
                            <td>{{ $item->writing_status }}</td>
                            <td class="small">{{ $item->writing_deadline_at?->format('Y-m-d') ?: '—' }}</td>
                            <td><a href="{{ route('admin.writing-queue.show', $item) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted p-3">No writing tasks match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if(method_exists($items, 'links'))
            <div class="p-3">{{ $items->links() }}</div>
        @endif
    </div>
</div>
@endsection
