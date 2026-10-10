@extends(staff_layout())

@section('title', 'Add sites in bulk')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Add sites in bulk</h4>
            <p class="text-muted mb-0 small">
                Up to {{ \App\Models\BulkSiteRequest::MAX_SITES_PER_REQUEST }} sites for one publisher.
                This opens <strong>one new bulk-request batch</strong>.
                <strong>Invite to Accept</strong> leaves every row waiting: Awaiting accept, not verified, not activated — one email and one bell, then they Accept each listing.
                <strong>Publish filled sites now</strong> puts every row live immediately (not verified), same as a publisher bulk request Done.
            </p>
        </div>
        <a href="{{ $sitesBackUrl }}" class="btn btn-sm btn-outline-secondary">← Back to Sites</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ staff_route('sites.bulk-store', [], false) }}" enctype="multipart/form-data" class="admin-deposits-filters" data-admin-filter-live="1" data-admin-select-no-submit="1" id="staffBulkAssignForm">
                @csrf
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="publisher_id">Publisher <span class="text-danger">*</span></label>
                        <select id="publisher_id" name="publisher_id" class="form-select @error('publisher_id') is-invalid @enderror" required
                                data-admin-select-search="1"
                                data-admin-select-search-label="Search publishers by name or email"
                                data-admin-select-search-empty="No publishers match">
                            <option value="">Select publisher…</option>
                            @foreach($publishers as $publisher)
                                <option value="{{ $publisher->id }}" @selected((int) old_text('publisher_id', $selectedPublisherId) === (int) $publisher->id)>
                                    {{ $publisher->name }} · {{ $publisher->email }}
                                    @if((int) ($publisher->sites_count ?? 0) > 0)
                                        ({{ (int) $publisher->sites_count }} {{ \Illuminate\Support\Str::plural('site', (int) $publisher->sites_count) }})
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('publisher_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="rows">Paste rows</label>
                        <textarea id="rows" name="rows" rows="10" class="form-control font-monospace @error('rows') is-invalid @enderror" placeholder="url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description">{{ old('rows') }}</textarea>
                        <div class="form-text">
                            One site per line. Columns: url, price, da, dr, traffic, country, language, site name, example url, turnaround (3days), publication (permanent), link type (dofollow), tag, niches separated by |, description.
                            A niche or description may contain commas. If the site name contains a comma, wrap that column in quotes.
                            A CSV with the same columns replaces the paste.
                        </div>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="csv_file">CSV file</label>
                        <input type="file" id="csv_file" name="csv_file" class="form-control @error('csv_file') is-invalid @enderror" accept=".csv,text/csv">
                        @error('csv_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input @error('written_request') is-invalid @enderror" type="checkbox" name="written_request" id="written_request" value="1" @checked(old('written_request')) required>
                            <label class="form-check-label" for="written_request">I have a written request from this publisher’s account email for these sites.</label>
                        </div>
                        @error('written_request')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    @if(is_array(session('bulk_row_errors')))
                        <div class="col-12">
                            <div class="alert alert-danger mb-0">
                                <div class="fw-semibold mb-2">Nothing was saved.</div>
                                <ul class="mb-0">
                                    @foreach(session('bulk_row_errors') as $failure)
                                        <li>Row {{ $failure['line'] ?? '?' }}: {{ implode(' ', $failure['errors'] ?? []) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    @error('save')
                        <div class="col-12"><div class="alert alert-danger mb-0">{{ $message }}</div></div>
                    @enderror

                    <div class="col-12">
                        <p class="small text-muted mb-2">Choose one action for every row in this paste.</p>
                        @error('publish_mode')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary" name="publish_mode" value="invite">Invite to Accept</button>
                            <button type="submit" class="btn btn-outline-primary" name="publish_mode" value="publish">Publish filled sites now</button>
                            <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    const form = document.getElementById('staffBulkAssignForm');
    if (!form) return;
    let confirmed = false;
    form.addEventListener('submit', function (e) {
        if (confirmed) return;
        const submitter = e.submitter;
        const mode = (submitter && submitter.getAttribute('name') === 'publish_mode')
            ? String(submitter.value || 'invite')
            : 'invite';
        if (mode !== 'publish' || typeof window.slbConfirm !== 'function') {
            return;
        }
        e.preventDefault();
        window.slbConfirm({
            title: 'Publish these sites now?',
            text: 'Every filled row goes live immediately (not verified). The publisher is notified and does not need to Accept.',
            confirmText: 'Publish now',
        }).then(function (ok) {
            if (!ok) return;
            confirmed = true;
            let hidden = form.querySelector('input[name="publish_mode"][data-staff-mode="1"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'publish_mode';
                hidden.setAttribute('data-staff-mode', '1');
                form.appendChild(hidden);
            }
            hidden.value = 'publish';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter || undefined);
            } else {
                HTMLFormElement.prototype.submit.call(form);
            }
        });
    });
})();
</script>
@endsection
