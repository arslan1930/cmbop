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
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.partners.payouts') }}">Payouts</a>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.partners.relay') }}">Relay</a>
    </form>

    @if (session('plain_api_key'))
        <div class="alert alert-warning">Copy this API key now: <code>{{ session('plain_api_key') }}</code></div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5>Payout mode</h5>
            <p class="small text-muted mb-2">Current: {{ $partner->payout_mode ?: 'held' }}. Instant requires a stored keep-live acceptance.</p>
            <form method="POST" action="{{ route('admin.partners.payout-mode', $partner) }}" class="row g-2">
                @csrf
                <div class="col-md-2">
                    <select name="mode" class="form-select">
                        <option value="held" @selected(($partner->payout_mode ?: 'held') === 'held')>held</option>
                        <option value="instant" @selected($partner->payout_mode === 'instant')>instant</option>
                    </select>
                </div>
                <div class="col-md-6"><input class="form-control" name="reason" required placeholder="Reason"></div>
                <div class="col-md-2"><button class="btn btn-primary">Update mode</button></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5>API keys</h5>
            <form method="POST" action="{{ route('admin.partners.api-keys', $partner) }}" class="row g-2 mb-3">
                @csrf
                <div class="col-md-4"><input class="form-control" name="name" required placeholder="Key name"></div>
                <div class="col-md-2">
                    <select name="mode" class="form-select">
                        <option value="live">live</option>
                        <option value="test">test</option>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary">Issue key</button></div>
            </form>
            <p class="small text-muted">Keys are also manageable by the partner on the dashboard Settings page. Max two active.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5>Pull adapter (partner API → import pipeline)</h5>
            <form method="POST" action="{{ route('admin.partners.pull', $partner) }}" class="row g-2">
                @csrf
                <div class="col-md-6">
                    <label class="form-label">API URL</label>
                    <input class="form-control" name="api_base_url" value="{{ $partner->connections->firstWhere('type', 'api_pull')?->api_base_url }}" placeholder="https://api.example/v1/sites">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Auth header</label>
                    <input class="form-control" name="auth_header_name" value="{{ $partner->connections->firstWhere('type', 'api_pull')?->auth_header_name ?: 'Authorization' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Auth key</label>
                    <input class="form-control" name="auth_key" placeholder="leave blank to keep">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Items path</label>
                    <input class="form-control" name="items_path" value="{{ $partner->connections->firstWhere('type', 'api_pull')?->items_path ?: 'data' }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Page param</label>
                    <input class="form-control" name="page_param" value="page">
                </div>
                <div class="col-12"><button class="btn btn-outline-primary">Save pull adapter</button></div>
            </form>
        </div>
    </div>
</div>
@endsection
