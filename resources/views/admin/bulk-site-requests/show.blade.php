@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ route('admin.bulk-site-requests.index') }}" class="small text-muted text-decoration-none">
            ← Bulk requests
        </a>
        <h3 class="mt-2 mb-1">Bulk request #{{ $bulkRequest->id }}</h3>
        <p class="text-muted small mb-0">
            Publisher: <strong>{{ $bulkRequest->publisher->name }}</strong>
            ({{ $bulkRequest->publisher->email }})
            · Status: <span class="text-capitalize">{{ str_replace('_', ' ', $bulkRequest->status) }}</span>
            · Estimated: {{ $bulkRequest->estimated_count ?? '—' }}
        </p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if(session('seed_failures'))
        <div class="alert alert-warning">
            <strong>Some seed rows failed</strong>
            <ul class="mb-0 small mt-2">
                @foreach(session('seed_failures') as $fail)
                    <li>Line {{ $fail['line'] }} · {{ $fail['url'] ?? '' }} — {{ implode('; ', $fail['errors'] ?? []) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold">Publisher note</h6>
                    <p class="small mb-0">{{ $bulkRequest->publisher_note ?: '—' }}</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">Ops actions</h6>
                    <form method="POST" action="{{ route('admin.bulk-site-requests.notes', $bulkRequest) }}" class="mb-3">
                        @csrf
                        <label class="form-label small">Internal notes</label>
                        <textarea name="admin_notes" class="form-control form-control-sm mb-2" rows="3">{{ old('admin_notes', $bulkRequest->admin_notes) }}</textarea>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Save notes</button>
                    </form>

                    @if($bulkRequest->isOpen())
                        <form method="POST" action="{{ route('admin.bulk-site-requests.sheet-sent', $bulkRequest) }}" class="mb-2">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                Mark sheet emailed
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.bulk-site-requests.cancel', $bulkRequest) }}"
                              onsubmit="return confirm('Cancel this bulk request?');">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel request</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Seed draft sites</h6>
                    <p class="small text-muted mb-3">
                        Paste one site per line after the publisher returns the sheet.
                        Columns: <code>url,price,da,dr,traffic,language,country[,site_name]</code>
                        (language/country = 2-letter marketplace codes). Drafts stay <strong>inactive</strong> until the publisher finishes details and you approve.
                    </p>
                    <form method="POST" action="{{ route('admin.bulk-site-requests.seed', $bulkRequest) }}">
                        @csrf
                        <textarea name="rows" class="form-control font-monospace small @error('rows') is-invalid @enderror" rows="10"
                                  placeholder="https://example.com,99,40,45,12000,de,de,Example Blog">{{ old('rows') }}</textarea>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-primary mt-3" @disabled(! $bulkRequest->isOpen())>
                            Seed drafts &amp; email publisher
                        </button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">Seeded sites ({{ $bulkRequest->sites->count() }})</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Site</th>
                                    <th>Price</th>
                                    <th>DR/DA</th>
                                    <th>Lang/Country</th>
                                    <th>Onboarding</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($bulkRequest->sites as $site)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $site->site_name }}</div>
                                            <div class="small text-muted">{{ $site->domain }}</div>
                                        </td>
                                        <td>€{{ number_format((float) $site->price, 2) }}</td>
                                        <td>{{ $site->dr }} / {{ $site->da }}</td>
                                        <td class="text-uppercase small">{{ $site->language }} / {{ $site->country }}</td>
                                        <td>
                                            <span class="badge text-bg-light border text-capitalize">
                                                {{ str_replace('_', ' ', $site->onboarding_status ?? '—') }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.sites.edit', $site->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted text-center py-3">No sites seeded yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
