@extends(staff_layout())

@section('title', 'Partner')

@section('content')
<div class="container-fluid py-3">
    <div class="mb-3">
        <a href="{{ route('admin.partners.index') }}" class="small">← Partners</a>
        <h4 class="mb-1 fw-bold">{{ $partner->displayName() }}</h4>
        <p class="text-muted small mb-0">Publisher <a href="{{ route('admin.users.show', $partner->user) }}">{{ $partner->user?->email }}</a> · Teqno LTD · no publisher/partner fees</p>
    </div>
    <form method="POST" action="{{ route('admin.partners.update', $partner) }}" class="card border-0 shadow-sm mb-4">
        @csrf
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Legal name</label>
                <input class="form-control" name="legal_name" value="{{ old('legal_name', $partner->legal_name) }}">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="active" @selected($partner->status === 'active')>active</option>
                    <option value="suspended" @selected($partner->status === 'suspended')>suspended</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Feed URL (CSV / XLSX / JSON / XML)</label>
                <input class="form-control" name="feed_url" value="{{ old('feed_url', $partner->feedConnection?->feed_url) }}" placeholder="https://…">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Internal notes</label>
                <textarea class="form-control" name="notes_internal" rows="2">{{ old('notes_internal', $partner->notes_internal) }}</textarea>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="trusted" value="1" @checked($partner->trusted)>
                    <label class="form-check-label">Trusted (auto-approve may go live)</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="agreement_signed" value="1" @checked($partner->agreement_signed)>
                    <label class="form-check-label">Agreement signed</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </div>
    </form>
    <form method="POST" action="{{ route('admin.partners.sync-feed', $partner) }}" class="mb-4">
        @csrf
        <button class="btn btn-outline-primary btn-sm" type="submit">Fetch feed now</button>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.partners.catalog', ['partner_id' => $partner->id]) }}">Catalog items</a>
    </form>
</div>
@endsection
