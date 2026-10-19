@extends(staff_layout())

@section('title', 'Add publisher on their behalf')

@section('content')
@php
    $mode = old('mode', $mode ?? 'single');
    $categories = $categories ?? collect();
    $languages = $languages ?? collect();
    $countries = $countries ?? collect();
    $rawNiches = old('categories', []);
    if (is_string($rawNiches)) {
        $rawNiches = preg_split('/\|/', $rawNiches) ?: [];
    }
    if (! is_iterable($rawNiches)) {
        $rawNiches = [];
    }
    $prefillNiches = \App\Models\Category::resolveNicheNames($rawNiches)['resolved'] ?? [];
    $prefillNiches = collect($prefillNiches)->flatten()->filter(fn ($v) => is_scalar($v) && filled($v))->map(fn ($v) => (string) $v)->values()->all();
@endphp
<div class="container-fluid py-3 staff-assign-site">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Add publisher on their behalf</h4>
            <p class="text-muted small mb-0">Create or reuse a publisher, add listings at the quoted price, and send one onboarding email. Invite to Accept is the only mode — they set a password (or use Google) then accept in My Sites.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.publishers.onboard.bulk') }}" class="btn btn-sm btn-outline-secondary">Bulk publishers CSV</a>
            <a href="{{ route('admin.publishers.onboardings') }}" class="btn btn-sm btn-outline-secondary">Onboarding status</a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.publishers.onboard.store') }}" enctype="multipart/form-data" class="admin-deposits-filters" data-admin-filter-live="1" data-admin-select-no-submit="1" id="publisherOnboardForm">
        @csrf

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Publisher</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="email">Email <span class="text-danger">*</span></label>
                        <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old_text('email') }}" required>
                        <div class="form-text">If this email already exists we reuse that account. A new account has no usable password until they click the invite.</div>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="name">Name</label>
                        <input type="text" id="name" name="name" class="form-control" value="{{ old_text('name') }}" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="publisher_country">Country</label>
                        <select id="publisher_country" name="country" class="form-select" data-admin-select-search="1">
                            <option value="">Optional…</option>
                            @foreach($countries as $country)
                                <option value="{{ marketplace_option_code($country) }}" @selected(old_text('country') === marketplace_option_code($country))>{{ marketplace_option_name($country) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="publisher_language">Language</label>
                        <select id="publisher_language" name="language" class="form-select">
                            <option value="">Optional…</option>
                            @foreach($languages as $language)
                                <option value="{{ marketplace_option_code($language) }}" @selected(old_text('language') === marketplace_option_code($language))>{{ marketplace_option_name($language, 'language') }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Used as the default site country/language when a pasted row leaves them blank.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">How many sites?</h5>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode_single" value="single" @checked($mode !== 'bulk')>
                    <label class="form-check-label" for="mode_single">One site (same fields as Add site for publisher)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="mode_bulk" value="bulk" @checked($mode === 'bulk')>
                    <label class="form-check-label" for="mode_bulk">Several sites (paste / CSV like bulk-create)</label>
                </div>
            </div>
        </div>

        <div id="singleSiteFields" class="{{ $mode === 'bulk' ? 'd-none' : '' }}">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="fw-semibold mb-3">Listing</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_name">Site name <span class="text-danger">*</span></label>
                            <input type="text" id="site_name" name="site_name" class="form-control @error('site_name') is-invalid @enderror" value="{{ old_text('site_name') }}" maxlength="255">
                            @error('site_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_url">Site URL <span class="text-danger">*</span></label>
                            <input type="text" id="site_url" name="site_url" class="form-control @error('site_url') is-invalid @enderror" value="{{ old_text('site_url') }}" placeholder="https://example.com">
                            @error('site_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="example_url">Example post URL <span class="text-danger">*</span></label>
                            <input type="text" id="example_url" name="example_url" class="form-control @error('example_url') is-invalid @enderror" value="{{ old_text('example_url') }}" placeholder="https://example.com/sample-post">
                            @error('example_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="price">Quoted price (€) <span class="text-danger">*</span></label>
                            <input type="number" id="price" name="price" class="form-control @error('price') is-invalid @enderror" min="0" step="0.01" value="{{ old_text('price') }}">
                            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA</label>
                            <input type="number" id="da" name="da" class="form-control" min="0" max="100" value="{{ old_text('da') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR</label>
                            <input type="number" id="dr" name="dr" class="form-control" min="0" max="100" value="{{ old_text('dr') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic</label>
                            <input type="number" id="traffic" name="traffic" class="form-control" min="0" value="{{ old_text('traffic') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="country">Site country</label>
                            <select id="country" name="site_country" class="form-select" data-admin-select-search="1">
                                <option value="">Use publisher country…</option>
                                @foreach($countries as $country)
                                    <option value="{{ marketplace_option_code($country) }}" @selected(old_text('site_country') === marketplace_option_code($country))>{{ marketplace_option_name($country) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="language">Site language</label>
                            <select id="language" name="site_language" class="form-select">
                                <option value="">Use publisher language…</option>
                                @foreach($languages as $language)
                                    <option value="{{ marketplace_option_code($language) }}" @selected(old_text('site_language') === marketplace_option_code($language))>{{ marketplace_option_name($language, 'language') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="categoryInput">Niches (max 7)</label>
                            <input type="hidden" name="categories" id="selectedCategories" value="{{ implode('|', $prefillNiches) }}">
                            <div class="multi-select-wrapper" id="categoryWrapper" data-multi-select="category">
                                <div class="multi-select-input" id="categoryInput" role="button" tabindex="0">
                                    <span class="multi-select-placeholder">Select niches (max 7)…</span>
                                </div>
                                <div class="multi-select-dropdown" id="categoryDropdown">
                                    <div class="multi-select-search">
                                        <input type="text" placeholder="Type to search niches…" id="categorySearch" autocomplete="off">
                                    </div>
                                    <div class="multi-select-options" id="categoryOptions">
                                        @foreach($categories as $categoryName)
                                            <div class="multi-select-option" data-value="{{ $categoryName }}" data-label="{{ $categoryName }}">{{ $categoryName }}</div>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none" id="categoryEmpty">No categories found</div>
                                </div>
                            </div>
                            @error('categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="turnaround_time">Turnaround</label>
                            <select id="turnaround_time" name="turnaround_time" class="form-select">
                                @foreach(['24h' => '24 hours', '48h' => '48 hours', '3days' => '3 days', '5days' => '5 days', '7days' => '7 days'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old_text('turnaround_time', '3days') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="publication_time">Publication time</label>
                            <select id="publication_time" name="publication_time" class="form-select">
                                @foreach(\App\Support\PublicationDuration::options() as $value => $label)
                                    <option value="{{ $value }}" @selected(old_text('publication_time', \App\Support\PublicationDuration::DEFAULT) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="link_type">Link type</label>
                            <select id="link_type" name="link_type" class="form-select">
                                <option value="dofollow" @selected(old_text('link_type', 'dofollow') === 'dofollow')>Dofollow</option>
                                <option value="nofollow" @selected(old_text('link_type') === 'nofollow')>Nofollow</option>
                            </select>
                        </div>
                        <div class="col-12">
                            @include('partials.site-description-editor', [
                                'value' => old_text('description', ''),
                                'required' => false,
                            ])
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="bulkSiteFields" class="{{ $mode === 'bulk' ? '' : 'd-none' }}">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="fw-semibold mb-3">Sites for this publisher</h5>
                    <label class="form-label fw-semibold" for="rows">Paste rows</label>
                    <textarea id="rows" name="rows" rows="8" class="form-control font-monospace @error('rows') is-invalid @enderror" placeholder="url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description">{{ old('rows') }}</textarea>
                    <div class="form-text mb-3">Same columns as <a href="{{ staff_route('sites.bulk-create') }}">CSV bulk create</a>. Email comes from the publisher field above.</div>
                    @error('rows')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <label class="form-label fw-semibold" for="csv_file">CSV file</label>
                    <input type="file" id="csv_file" name="csv_file" class="form-control @error('csv_file') is-invalid @enderror" accept=".csv,text/csv">
                    @error('csv_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        @if(is_array(session('bulk_row_errors')))
            <div class="alert alert-danger">
                <div class="fw-semibold mb-2">Nothing was saved.</div>
                <ul class="mb-0">
                    @foreach(session('bulk_row_errors') as $failure)
                        <li>Row {{ $failure['line'] ?? '?' }}: {{ implode(' ', $failure['errors'] ?? []) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @error('save')<div class="alert alert-danger">{{ $message }}</div>@enderror

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="form-check">
                    <input class="form-check-input @error('written_request') is-invalid @enderror" type="checkbox" name="written_request" id="written_request" value="1" @checked(old('written_request')) required>
                    <label class="form-check-label" for="written_request">I have a written request from this publisher’s account email.</label>
                </div>
                @error('written_request')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Invite to Accept</button>
        <a href="{{ route('admin.publishers.onboardings') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
<link href="{{ same_origin_asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ same_origin_asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script>
(function () {
    const single = document.getElementById('singleSiteFields');
    const bulk = document.getElementById('bulkSiteFields');
    document.querySelectorAll('input[name="mode"]').forEach(function (el) {
        el.addEventListener('change', function () {
            const isBulk = el.value === 'bulk' && el.checked;
            if (single) single.classList.toggle('d-none', isBulk);
            if (bulk) bulk.classList.toggle('d-none', !isBulk);
        });
    });
    const prefills = @json($prefillNiches);
    if (typeof window.initMultiSelect === 'function') {
        const ms = window.initMultiSelect({
            wrapperId: 'categoryWrapper',
            inputId: 'categoryInput',
            dropdownId: 'categoryDropdown',
            optionsId: 'categoryOptions',
            hiddenInputId: 'selectedCategories',
            searchId: 'categorySearch',
            emptyId: 'categoryEmpty',
            maxSelections: 7,
            placeholderText: 'Select niches (max 7)…',
        });
        if (ms && prefills.length) ms.setSelectedItems(prefills, prefills);
    }
})();
</script>
@endsection
