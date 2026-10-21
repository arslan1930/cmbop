@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Writing task #'.($item->order?->order_number ?? $item->order_id),
        'subtitle' => $item->site_name,
    ])

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="mb-1"><strong>Status:</strong> {{ $item->writing_status }}</p>
            <p class="mb-1"><strong>Language:</strong> {{ $item->writing_language ?: '—' }}</p>
            <p class="mb-1"><strong>Target URL:</strong> {{ $item->target_url ?: '—' }}</p>
            <p class="mb-1"><strong>Anchor:</strong> {{ $item->anchor_text ?: '—' }}</p>
            <p class="mb-3"><strong>Deadline:</strong> {{ $item->writing_deadline_at?->format('j M Y') ?: '—' }}</p>

            <form method="POST" action="{{ route('admin.writing-queue.assign', $item) }}" class="mb-3">
                @csrf
                <button class="btn btn-outline-primary">Assign to me</button>
            </form>

            <form method="POST" action="{{ route('admin.writing-queue.upload', $item) }}" enctype="multipart/form-data">
                @csrf
                <label class="form-label">Upload article</label>
                <input type="file" name="article" class="form-control mb-2" required>
                <label class="form-label">Notes (optional)</label>
                <textarea name="notes" class="form-control mb-3" rows="3">{{ $item->writing_brief_notes }}</textarea>
                <button class="btn btn-primary">Submit for advertiser approval</button>
            </form>
        </div>
    </div>
</div>
@endsection
