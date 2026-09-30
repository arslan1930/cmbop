@extends(staff_layout())

@section('title', 'Add sites in bulk')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Add sites in bulk</h4>
            <p class="text-muted mb-0 small">
                Up to {{ \App\Models\BulkSiteRequest::MAX_SITES_PER_REQUEST }} sites for one publisher.
                Each one is an invite: Awaiting accept, not verified, and not activated.
                The publisher gets one email and one bell.
            </p>
        </div>
        <a href="{{ $sitesBackUrl }}" class="btn btn-sm btn-outline-secondary">← Back to Sites</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ staff_route('sites.bulk-store', [], false) }}" enctype="multipart/form-data" class="admin-deposits-filters" data-admin-filter-live="1">
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

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Add sites &amp; notify</button>
                        <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
