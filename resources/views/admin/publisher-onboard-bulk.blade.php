@extends(staff_layout())

@section('title', 'Bulk publisher onboarding')

@section('content')
@php
    $preview = $preview ?? session('onboard_preview');
    $preview = is_array($preview) ? $preview : null;
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Bulk publisher onboarding</h4>
            <p class="text-muted mb-0 small">
                One row per site. Rows with the same email become one publisher and receive one email.
                Preview first — nothing is saved until you confirm.
                Columns: email, name, url, price, country, language, da, dr, traffic, site_name, example_url, turnaround, publication, link_type, tag, niches, description.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.publishers.onboard') }}" class="btn btn-sm btn-outline-secondary">Single publisher</a>
            <a href="{{ route('admin.publishers.onboardings') }}" class="btn btn-sm btn-outline-secondary">Onboarding status</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.publishers.onboard.bulk.preview') }}" enctype="multipart/form-data" class="admin-deposits-filters" id="onboardBulkForm">
                @csrf
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="rows">Paste rows</label>
                        <textarea id="rows" name="rows" rows="10" class="form-control font-monospace @error('rows') is-invalid @enderror" placeholder="email,name,url,price,country,language,da,dr,traffic,site_name,example_url,turnaround,publication,link_type,tag,niches,description">{{ old('rows') }}</textarea>
                        @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="csv_file">CSV file</label>
                        <input type="file" id="csv_file" name="csv_file" class="form-control @error('csv_file') is-invalid @enderror" accept=".csv,text/csv">
                        @error('csv_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @error('save')
                        <div class="col-12"><div class="alert alert-danger mb-0">{{ $message }}</div></div>
                    @enderror
                    @error('confirm')
                        <div class="col-12"><div class="alert alert-danger mb-0">{{ $message }}</div></div>
                    @enderror
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary">Dry-run preview</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if($preview)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Preview</h5>
                <p class="small text-muted">{{ (int) ($preview['publisher_count'] ?? 0) }} publisher(s), {{ (int) ($preview['ready_count'] ?? 0) }} valid site row(s). One email per publisher after commit.</p>
                @if(!empty($preview['failures']))
                    <div class="alert alert-danger">
                        <div class="fw-semibold mb-2">Fix these rows. Nothing will be saved.</div>
                        <ul class="mb-0">
                            @foreach($preview['failures'] as $failure)
                                <li>Row {{ $failure['line'] ?? '?' }}: {{ implode(' ', $failure['errors'] ?? []) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(!empty($preview['groups']))
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Name</th>
                                    <th>Account</th>
                                    <th>Sites</th>
                                    <th>Errors</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['groups'] as $group)
                                    <tr>
                                        <td>{{ $group['email'] ?? '' }}</td>
                                        <td>{{ $group['name'] ?? '' }}</td>
                                        <td>{{ !empty($group['exists']) ? 'Reuse existing' : 'Create new' }}</td>
                                        <td>{{ implode(', ', $group['sites'] ?? []) }}</td>
                                        <td>{{ implode(' ', $group['errors'] ?? []) ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if(!empty($preview['can_commit']))
                    <form method="POST" action="{{ route('admin.publishers.onboard.bulk.store') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="rows" value="{{ old('rows') }}">
                        <input type="hidden" name="confirm" value="1">
                        <p class="small text-muted">Re-upload the same CSV if you used a file — paste is kept from the preview.</p>
                        <input type="file" name="csv_file" class="form-control mb-3" accept=".csv,text/csv">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="as_partner" id="as_partner_bulk" value="1">
                            <label class="form-check-label" for="as_partner_bulk">Mark these publishers as catalog partners (Teqno LTD). No fees.</label>
                        </div>
                        <button type="submit" class="btn btn-primary">Create publishers and send invites</button>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
