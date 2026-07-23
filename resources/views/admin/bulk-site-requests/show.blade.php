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
            · Sites submitted: {{ $bulkRequest->items->count() ?: ($bulkRequest->estimated_count ?? '—') }}
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
                                Mark sheet emailed (optional)
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.bulk-site-requests.cancel', $bulkRequest) }}"
                              onsubmit="return confirm('Cancel this bulk request? History is kept.');">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel request</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">History</h6>
                    <p class="small text-muted mb-3">Append-only audit trail. Cannot be deleted.</p>
                    <div class="bulk-history-list" style="max-height: 28rem; overflow-y: auto;">
                        @forelse($history as $entry)
                            <div class="border-bottom py-2 small">
                                <div class="fw-semibold">{{ $entry->action }}</div>
                                <div class="text-muted">{{ $entry->description }}</div>
                                <div class="text-muted mt-1" style="font-size:.72rem;">
                                    {{ $entry->user_name ?? 'System' }}
                                    @if($entry->role) · {{ $entry->role }} @endif
                                    · {{ $entry->created_at?->timezone(config('app.timezone'))->format('M j, Y H:i') }}
                                </div>
                            </div>
                        @empty
                            <p class="small text-muted mb-0">No history yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Publisher submitted (URL + price only)</h6>
                    <p class="small text-muted mb-3">
                        Implement from this list: add DA/DR/traffic/language/country when seeding drafts below.
                        The publisher will finish descriptions afterward.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Website URL</th>
                                    <th>Price</th>
                                    <th>Domain</th>
                                    <th>Seeded?</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($bulkRequest->items as $item)
                                    <tr>
                                        <td>
                                            <a href="{{ $item->site_url }}" target="_blank" rel="noopener noreferrer">
                                                {{ $item->site_url }}
                                            </a>
                                        </td>
                                        <td>€{{ number_format((float) $item->price, 2) }}</td>
                                        <td class="small text-muted">{{ $item->domain }}</td>
                                        <td>
                                            @if($item->site_id)
                                                <span class="badge text-bg-success">Yes</span>
                                            @else
                                                <span class="badge text-bg-light border">Pending</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted text-center py-3">
                                            No URL + price rows (legacy request before in-app submission).
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Seed draft sites (add metrics)</h6>
                    <p class="small text-muted mb-3">
                        Paste one site per line using the publisher’s URLs and prices, plus your metrics.
                        Columns: <code>url,price,da,dr,traffic,language,country[,site_name]</code>
                        (language/country = 2-letter marketplace codes). Drafts stay <strong>inactive</strong> until the publisher finishes details and you approve.
                    </p>
                    @php
                        $seedStarter = $bulkRequest->items->map(function ($item) {
                            return $item->site_url.','.$item->price.',da,dr,traffic,lang,country';
                        })->implode("\n");
                    @endphp
                    @if($seedStarter !== '')
                        <div class="small mb-2">
                            <span class="text-muted">Starter from publisher URL + price (replace da/dr/traffic/lang/country):</span>
                            <pre class="bg-light border rounded p-2 small mb-2 mt-1" id="bulkSeedStarter" style="max-height:8rem;overflow:auto;">{{ $seedStarter }}</pre>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulkCopySeedStarter">Copy starter into box</button>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('admin.bulk-site-requests.seed', $bulkRequest) }}">
                        @csrf
                        <textarea name="rows" id="bulkSeedRows" class="form-control font-monospace small @error('rows') is-invalid @enderror" rows="10"
                                  placeholder="https://example.com,99,40,45,12000,de,de,Example Blog">{{ old('rows') }}</textarea>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text mb-2">
                            Columns: <code>url,price,da,dr,traffic,language,country[,site_name]</code>
                        </div>
                        <button type="submit" class="btn btn-primary mt-2" @disabled(! $bulkRequest->isOpen())>
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
                                    <tr id="bulk-site-row-{{ $site->id }}">
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
                                        <td class="text-end text-nowrap">
                                            <a href="{{ route('admin.sites.edit', $site->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                            @if($canDeleteDrafts && (auth()->user()->isAdmin() || $site->canBeDeletedByMarketing()))
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-danger bulk-draft-delete"
                                                        data-site-id="{{ $site->id }}"
                                                        data-site-name="{{ $site->site_name }}">
                                                    Delete
                                                </button>
                                            @endif
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

<script>
document.getElementById('bulkCopySeedStarter')?.addEventListener('click', function () {
    const starter = document.getElementById('bulkSeedStarter');
    const box = document.getElementById('bulkSeedRows');
    if (!starter || !box) return;
    box.value = starter.textContent.trim();
    box.focus();
});

document.querySelectorAll('.bulk-draft-delete').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = this.getAttribute('data-site-id');
        const name = this.getAttribute('data-site-name') || 'this site';
        if (!confirm('Delete draft “' + name + '”? This removes the wrong seed. History of the delete is kept.')) {
            return;
        }
        this.disabled = true;
        try {
            const res = await fetch(@json(url('/admin/sites')) + '/' + id, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': @json(csrf_token()),
                    'Accept': 'application/json',
                },
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok || !data.success) {
                alert(data.message || 'Could not delete site.');
                this.disabled = false;
                return;
            }
            location.reload();
        } catch (e) {
            alert('Could not delete site.');
            this.disabled = false;
        }
    });
});
</script>
@endsection
