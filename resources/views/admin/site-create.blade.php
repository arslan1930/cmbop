@extends(staff_layout())

@section('title', 'Add site for publisher')

@section('content')
@php
    $categories = $categories ?? collect();
    $languages = $languages ?? collect();
    $isMarketingEditor = $isMarketingEditor ?? false;
    $selectedPublisherId = (int) ($selectedPublisherId ?? 0);
    $selectedPublisherUnverified = $selectedPublisherUnverified ?? false;
    $sitesBackUrl = $sitesBackUrl ?? staff_route('sites.index');
    $prefillSiteName = $prefillSiteName ?? '';
    $prefillSiteUrl = $prefillSiteUrl ?? '';
    $prefillExampleUrl = $prefillExampleUrl ?? '';
    $prefillCountry = $prefillCountry ?? '';
    $prefillLanguage = $prefillLanguage ?? '';
    $prefillSuggestionNotes = $prefillSuggestionNotes ?? '';
    $occupyingListingUrl = $occupyingListingUrl ?? null;
    $suggestionId = (int) ($suggestionId ?? 0);
    $bulkCreateUrl = staff_route('sites.bulk-create', array_filter([
        'publisher' => $selectedPublisherId > 0 ? $selectedPublisherId : null,
    ]), false);
    $rawNiches = old('categories', []);
    if (! is_string($rawNiches) && ! is_iterable($rawNiches)) {
        $rawNiches = [];
    }
    if (is_string($rawNiches)) {
        $rawNiches = preg_split('/\|/', $rawNiches) ?: [];
    }
    $prefillNiches = \App\Models\Category::resolveNicheNames($rawNiches)['resolved'] ?? [];
    if (is_string($prefillNiches)) {
        $prefillNiches = array_values(array_filter(array_map('trim', preg_split('/\|/', $prefillNiches) ?: [])));
    }
    $prefillNiches = collect($prefillNiches)
        ->flatten()
        ->filter(fn ($v) => is_scalar($v) && filled($v))
        ->map(fn ($v) => (string) $v)
        ->values()
        ->all();
@endphp
<div class="container-fluid py-3 staff-assign-site">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Add site for publisher</h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ $bulkCreateUrl }}" class="btn btn-sm btn-outline-secondary">CSV bulk create</a>
            <a href="{{ $sitesBackUrl }}" class="btn btn-sm btn-outline-secondary">← Back to Sites</a>
        </div>
    </div>

    <div class="staff-sites-strip mb-2" aria-label="Listing pipeline">
        @foreach(['Invite', 'Accept', 'Verify', 'Activate'] as $stepIndex => $stepLabel)
            <div class="staff-sites-strip__cell {{ $stepIndex === 0 ? 'is-active' : '' }}">
                <span class="staff-sites-strip__count">{{ $stepIndex + 1 }}</span>
                <span class="staff-sites-strip__label">{{ $stepLabel }}</span>
            </div>
        @endforeach
    </div>
    <p class="small text-muted mb-3 staff-sites-strip-hint">
        Saving starts <strong>Invite</strong> only. The publisher must Accept, then
        @if($isMarketingEditor)
            admin verifies first (TXT badge). You Activate only after that — and only if DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }}, DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}, traffic ≥ {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}, and a marketplace country is set.
        @else
            verify (TXT badge) before Activate. Accept ≠ Verified, and catalog Activate is not automatic.
        @endif
        See the <a href="{{ staff_route('staff-handbook', [], false) }}">{{ __('messages.staff_handbook_title') }}</a>.
        Many sites for one publisher? Use <a href="{{ $bulkCreateUrl }}">CSV bulk create</a>
        — that also opens one new batch.
    </p>

    @if(filled($occupyingListingUrl))
        <div class="alert alert-warning border-0 py-2 px-3 small mb-3" role="status">
            This domain is already in the catalog.
            <a href="{{ $occupyingListingUrl }}" class="alert-link">Open listing</a>
            instead of creating a duplicate.
        </div>
    @endif

    @if($prefillSuggestionNotes !== '')
        <div class="alert alert-secondary border-0 py-2 px-3 small mb-3" role="note">
            <strong class="d-block mb-1">Suggester notes</strong>
            {{ $prefillSuggestionNotes }}
        </div>
    @endif

    <form method="POST" action="{{ staff_route('sites.store', [], false) }}" enctype="multipart/form-data"
          id="staffAssignSiteForm"
          class="staff-assign-site-form admin-deposits-filters"
          data-admin-filter-live="1"
          data-admin-select-no-submit="1">
        @csrf
        @if((int) old_text('suggestion_id', $suggestionId) > 0)
            <input type="hidden" name="suggestion_id" value="{{ (int) old_text('suggestion_id', $suggestionId) }}">
            <div class="alert alert-info border-0 py-2 px-3 small mb-3">
                Prefilling from website suggestion #{{ (int) old_text('suggestion_id', $suggestionId) }}. Saving this listing will mark that suggestion accepted.
            </div>
        @endif

        <div class="card border-0 shadow-sm mb-3 staff-assign-site-section">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Publisher</h5>
                <label class="form-label fw-semibold" for="publisher_id">Publisher <span class="text-danger">*</span></label>
                <select id="publisher_id" name="publisher_id" class="form-select @error('publisher_id') is-invalid @enderror" required
                        data-admin-select-search="1"
                        data-admin-select-search-url="{{ staff_route('sites.publishers-search', [], false) }}"
                        data-admin-select-search-label="Search publishers by name, email, or domain"
                        data-admin-select-search-empty="No publishers match"
                        data-admin-select-placeholder="Select publisher…">
                    <option value="">Select publisher…</option>
                    @foreach($publishers as $publisher)
                        <option value="{{ $publisher->id }}"
                            data-verified="{{ $publisher->hasVerifiedEmail() ? '1' : '0' }}"
                            @selected((int) old_text('publisher_id', $selectedPublisherId) === (int) $publisher->id)>
                            {{ $publisher->name }} · {{ $publisher->email }}
                            @if((int) ($publisher->sites_count ?? 0) > 0)
                                ({{ (int) $publisher->sites_count }} {{ \Illuminate\Support\Str::plural('site', (int) $publisher->sites_count) }})
                            @endif
                            @if(! $publisher->hasVerifiedEmail())
                                · unverified
                            @endif
                        </option>
                    @endforeach
                </select>
                <div class="form-text">Type a name, email, or domain to find a verified-email publisher. Suspended accounts are left out.</div>
                <div class="staff-assign-publisher-dossier mt-2 d-none" id="publisherDossier">
                    <div class="small mb-1" id="publisherDossierMeta"></div>
                    <div class="small text-muted" id="publisherDomains"></div>
                </div>
                <div class="alert alert-warning border-0 py-2 px-3 small mb-0 mt-2 {{ $selectedPublisherUnverified ? '' : 'd-none' }}" id="unverifiedPublisherWarn" role="status">
                    This publisher has not verified their email. They cannot log in to Accept the invite until they verify. Choose a verified publisher before saving.
                </div>
                @error('publisher_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3 staff-assign-site-section">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Listing URLs</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_name">Site name <span class="text-danger">*</span></label>
                        <input type="text" id="site_name" name="site_name" class="form-control @error('site_name') is-invalid @enderror"
                               value="{{ old_text('site_name', $prefillSiteName) }}" required maxlength="255">
                        <div class="form-text">
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="fillNameFromUrlBtn">Fill name from URL</button>
                        </div>
                        @error('site_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_url">Site URL <span class="text-danger">*</span></label>
                        <input type="text" id="site_url" name="site_url" class="form-control @error('site_url') is-invalid @enderror"
                               value="{{ old_text('site_url', $prefillSiteUrl) }}" required placeholder="https://example.com">
                        <div class="form-text" id="siteUrlCanonical"></div>
                        <div class="form-text" id="siteUrlStatus" role="status"></div>
                        @error('site_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="example_url">Example post URL <span class="text-danger">*</span></label>
                        <input type="text" id="example_url" name="example_url" class="form-control @error('example_url') is-invalid @enderror"
                               value="{{ old_text('example_url', $prefillExampleUrl) }}" required placeholder="https://example.com/sample-post">
                        <div class="form-text">
                            Must be on the same domain as the site URL.
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="fillExampleFromUrlBtn">Use origin/sample-post</button>
                        </div>
                        @error('example_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3 staff-assign-site-section">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Metrics &amp; market</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="price">Price (€) <span class="text-danger">*</span></label>
                        <input type="number" id="price" name="price" class="form-control @error('price') is-invalid @enderror"
                               min="0" step="0.01" required value="{{ old_text('price') }}">
                        @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary staff-assign-lookup" id="lookupMetricsBtn">
                            Look up metrics
                        </button>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="da">DA <span class="text-danger">*</span></label>
                        <input type="number" id="da" name="da" class="form-control @error('da') is-invalid @enderror"
                               min="0" max="100" step="1" inputmode="numeric" required
                               placeholder="0–100" value="{{ old_text('da') }}">
                        <div class="form-text">Domain Authority (0–100). Whole numbers only.</div>
                        @error('da')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="dr">DR <span class="text-danger">*</span></label>
                        <input type="number" id="dr" name="dr" class="form-control @error('dr') is-invalid @enderror"
                               min="0" max="100" step="1" inputmode="numeric" required
                               placeholder="0–100" value="{{ old_text('dr') }}">
                        <div class="form-text">Domain Rating (0–100). Whole numbers only.</div>
                        @error('dr')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="traffic">Traffic <span class="text-danger">*</span></label>
                        <input type="number" id="traffic" name="traffic" class="form-control @error('traffic') is-invalid @enderror"
                               min="0" max="4294967295" step="1" inputmode="numeric" required
                               placeholder="e.g. 1500000" value="{{ old_text('traffic') }}">
                        <div class="form-text">Monthly organic visits (whole number).</div>
                        @error('traffic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <div class="form-text mb-0" id="metricsStatus" role="status"></div>
                        <div class="form-text mb-0" id="qualityBarStatic"
                             data-min-da="{{ \App\Models\Site::GOOD_MIN_DA }}"
                             data-min-dr="{{ \App\Models\Site::GOOD_MIN_DR }}"
                             data-min-traffic="{{ \App\Models\Site::GOOD_MIN_TRAFFIC }}">
                            Marketing Activate needs DA ≥ {{ \App\Models\Site::GOOD_MIN_DA }}, DR ≥ {{ \App\Models\Site::GOOD_MIN_DR }}, and traffic ≥ {{ number_format(\App\Models\Site::GOOD_MIN_TRAFFIC) }}. Saving below this is allowed.
                        </div>
                        <div class="alert alert-warning border-0 py-2 px-3 small d-none mb-0 mt-2" id="qualityBarWarn" role="status">
                            These metrics are below the marketing Activate bar. You can still save — admin must verify, and marketing will not be able to Activate until the bar is met.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="country">Country <span class="text-danger">*</span></label>
                        <select id="country" name="country" class="form-select @error('country') is-invalid @enderror" required
                                data-admin-select-search="1"
                                data-admin-select-search-label="Search countries"
                                data-admin-select-search-empty="No countries match">
                            <option value="">Select…</option>
                            @foreach($countries as $country)
                                <option value="{{ strtolower($country->code) }}"
                                    @selected(old_text('country', $prefillCountry) === strtolower($country->code))>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Pick country first.</div>
                        @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="language">Language <span class="text-danger">*</span></label>
                        <input type="hidden" id="selectedLanguage" value="{{ old_text('language', $prefillLanguage) }}">
                        <select id="language" name="language" class="form-select @error('language') is-invalid @enderror" required>
                            <option value="">{{ old_text('country', $prefillCountry) !== '' ? 'Select…' : 'Select country first' }}</option>
                            @foreach($languages as $language)
                                <option value="{{ strtolower($language->code) }}"
                                    @selected(old_text('language', $prefillLanguage) === strtolower($language->code))>
                                    {{ $language->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Only languages paired with that country.</div>
                        @error('language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="categoryInput">Niches <span class="text-danger">*</span> (max 7)</label>
                        <input type="hidden" name="categories" id="selectedCategories" value="{{ implode('|', $prefillNiches) }}">
                        <div class="multi-select-wrapper" id="categoryWrapper" data-multi-select="category">
                            <div class="multi-select-input" id="categoryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select niches">
                                <span class="multi-select-placeholder">Select niches (max 7)…</span>
                            </div>
                            <div class="multi-select-dropdown" id="categoryDropdown" role="listbox" aria-multiselectable="true">
                                <div class="multi-select-search">
                                    <input type="text" placeholder="Type to search niches…" id="categorySearch" autocomplete="off" aria-label="Search niches">
                                </div>
                                <div class="multi-select-options" id="categoryOptions">
                                    @foreach($categories as $categoryName)
                                        <div class="multi-select-option"
                                             role="option"
                                             data-value="{{ $categoryName }}"
                                             data-label="{{ $categoryName }}">{{ $categoryName }}</div>
                                    @endforeach
                                </div>
                                <div class="multi-select-empty d-none" id="categoryEmpty" role="status">No categories found</div>
                            </div>
                        </div>
                        <div class="form-text">Click to toggle; type to search; Enter adds the highlighted match. Max 7.</div>
                        @error('categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="turnaround_time">Turnaround <span class="text-danger">*</span></label>
                        <select id="turnaround_time" name="turnaround_time" class="form-select @error('turnaround_time') is-invalid @enderror" required>
                            @foreach(['24h' => '24 hours', '48h' => '48 hours', '3days' => '3 days', '5days' => '5 days', '7days' => '7 days'] as $value => $label)
                                <option value="{{ $value }}" @selected(old_text('turnaround_time', '3days') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('turnaround_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="publication_time">Publication time <span class="text-danger">*</span></label>
                        <select id="publication_time" name="publication_time" class="form-select @error('publication_time') is-invalid @enderror" required>
                            @foreach(['6months' => '6 months', '1year' => '1 year', 'permanent' => 'Permanent'] as $value => $label)
                                <option value="{{ $value }}" @selected(old_text('publication_time', 'permanent') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('publication_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="link_type">Link type <span class="text-danger">*</span></label>
                        <select id="link_type" name="link_type" class="form-select @error('link_type') is-invalid @enderror" required>
                            <option value="dofollow" @selected(old_text('link_type', 'dofollow') === 'dofollow')>Dofollow</option>
                            <option value="nofollow" @selected(old_text('link_type') === 'nofollow')>Nofollow</option>
                        </select>
                        @error('link_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold d-block">Site tag</label>
                        <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="Site tag">
                            @foreach(\App\Support\SiteTag::staffFormOptions() as $value => $label)
                                @php $tagId = $value === '' ? 'tag_none' : 'tag_'.$value; @endphp
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="site_tag" id="{{ $tagId }}"
                                           value="{{ $value }}" @checked(old_text('site_tag', '') === $value)>
                                    <label class="form-check-label" for="{{ $tagId }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_image">Site image</label>
                        <input type="file" id="site_image" name="site_image"
                               class="form-control @error('site_image') is-invalid @enderror"
                               accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                               data-max-kb="{{ \App\Support\SiteImageUpload::maxKilobytes() }}"
                               data-php-max-kb="{{ \App\Support\SiteImageUpload::phpUploadMaxKilobytes() }}">
                        <div class="form-text">Optional desktop screenshot (JPEG, PNG, GIF, or WebP up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }}&nbsp;MB). If you skip it, a screenshot is queued after save.</div>
                        <img id="siteImagePreview" alt="" class="d-none mt-2 rounded border" style="max-width: 240px; max-height: 140px;">
                        @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        @include('partials.site-description-editor', [
                            'value' => old_text('description', ''),
                            'required' => true,
                        ])
                    </div>
                </div>
            </div>
        </div>

        @include('admin.sites.partials.placement-extras', ['layout' => 'card', 'site' => null])

        <div class="card border-0 shadow-sm mb-3 staff-assign-site-section">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Written request</h5>
                <div class="form-check">
                    <input class="form-check-input @error('written_request') is-invalid @enderror"
                           type="checkbox" name="written_request" id="written_request" value="1"
                           @checked(old('written_request')) required>
                    <label class="form-check-label" for="written_request">
                        I have a written request from this publisher’s account email
                    </label>
                </div>
                <div class="form-text mb-3">Handbook: only after a ticket, email, or in-product chat from that account.</div>
                @error('written_request')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <label class="form-label fw-semibold" for="request_source">Request source <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" id="request_source" name="request_source" class="form-control" maxlength="120"
                       value="{{ old_text('request_source') }}"
                       placeholder="Ticket #, email subject, or chat date">
                <div class="form-text">Stored on the activity log only — not shown to the publisher.</div>
            </div>
        </div>

        <div class="staff-assign-site-save">
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn"
                        @disabled($selectedPublisherUnverified)>
                    <i class="fa fa-plus me-1"></i> Add site &amp; notify publisher
                </button>
                <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </form>
</div>

<link href="{{ same_origin_asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ same_origin_asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="{{ same_origin_asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script src="{{ same_origin_asset('assets/js/site-image-upload.js') }}?v={{ @filemtime(public_path('assets/js/site-image-upload.js')) ?: '1' }}"></script>
<script>
(function () {
    const map = @json($countryLanguageMap ?? new \stdClass());
    const countryEl = document.getElementById('country');
    const langEl = document.getElementById('language');
    const langHidden = document.getElementById('selectedLanguage');
    const preferredLang = @json(old_text('language', $prefillLanguage));
    const imageInput = document.getElementById('site_image');
    if (imageInput && window.SiteImageUpload) {
        window.SiteImageUpload.bindSiteImageInput({
            input: imageInput,
            onError: function (title) {
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: title });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
                }
            },
        });
    }
    const qualityBar = document.getElementById('qualityBarStatic');
    const qualityWarn = document.getElementById('qualityBarWarn');
    const minDa = parseInt((qualityBar && qualityBar.getAttribute('data-min-da')) || '30', 10);
    const minDr = parseInt((qualityBar && qualityBar.getAttribute('data-min-dr')) || '30', 10);
    const minTraffic = parseInt((qualityBar && qualityBar.getAttribute('data-min-traffic')) || '10000', 10);

    function refreshQualityBar() {
        if (!qualityWarn) return;
        const da = parseInt((document.getElementById('da') || {}).value, 10);
        const dr = parseInt((document.getElementById('dr') || {}).value, 10);
        const traffic = parseInt((document.getElementById('traffic') || {}).value, 10);
        const filled = Number.isFinite(da) && Number.isFinite(dr) && Number.isFinite(traffic);
        const below = filled && (da < minDa || dr < minDr || traffic < minTraffic);
        qualityWarn.classList.toggle('d-none', !below);
    }
    ['da', 'dr', 'traffic'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', refreshQualityBar);
    });
    refreshQualityBar();

    function syncLanguageHidden() {
        if (!langHidden) return;
        if (langEl && !langEl.disabled) {
            langHidden.value = String(langEl.value || '').toLowerCase();
            return;
        }
        langHidden.value = String(langHidden.value || '').toLowerCase();
    }

    function languageValue() {
        if (langEl && !langEl.disabled && String(langEl.value || '').trim()) {
            return String(langEl.value).toLowerCase();
        }
        return String((langHidden && langHidden.value) || '').toLowerCase();
    }

    function refreshLanguages() {
        if (!countryEl || !langEl) return;
        const code = (countryEl.value || '').toLowerCase();
        const list = map[code] || [];
        const keep = (
            (langEl && String(langEl.value || '').trim())
            || (langHidden && String(langHidden.value || '').trim())
            || preferredLang
            || ''
        ).toLowerCase();
        langEl.innerHTML = '';
        if (!code) {
            langEl.disabled = true;
            langEl.innerHTML = '<option value="">Select country first</option>';
            if (langHidden) langHidden.value = '';
            langEl.dispatchEvent(new Event('admin-select-refresh'));
            return;
        }
        langEl.disabled = false;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select…';
        langEl.appendChild(placeholder);
        list.forEach(function (row) {
            const opt = document.createElement('option');
            opt.value = row.code;
            opt.textContent = row.name || String(row.code).toUpperCase();
            if (keep && keep === String(row.code).toLowerCase()) opt.selected = true;
            langEl.appendChild(opt);
        });
        if (list.length === 1) {
            langEl.value = list[0].code;
        }
        syncLanguageHidden();
        langEl.dispatchEvent(new Event('admin-select-refresh'));
    }

    if (countryEl) {
        countryEl.addEventListener('change', function () {
            refreshLanguages();
        });
        refreshLanguages();
    }
    if (langEl) {
        langEl.addEventListener('change', syncLanguageHidden);
    }

    const prefills = @json($prefillNiches);
    const ms = typeof window.initMultiSelect === 'function'
        ? window.initMultiSelect({
            wrapperId: 'categoryWrapper',
            inputId: 'categoryInput',
            dropdownId: 'categoryDropdown',
            optionsId: 'categoryOptions',
            hiddenInputId: 'selectedCategories',
            searchId: 'categorySearch',
            emptyId: 'categoryEmpty',
            maxSelections: 7,
            placeholderText: 'Select niches (max 7)…',
        })
        : null;
    if (ms && prefills.length) {
        ms.setSelectedItems(prefills, prefills);
    }

    const publisherSelect = document.getElementById('publisher_id');
    const unverifiedWarn = document.getElementById('unverifiedPublisherWarn');
    const publisherDossier = document.getElementById('publisherDossier');
    const publisherDossierMeta = document.getElementById('publisherDossierMeta');
    const publisherDomains = document.getElementById('publisherDomains');
    const assignSubmitBtn = document.getElementById('assignSubmitBtn');
    const domainCheckUrl = {!! json_encode(staff_route('sites.domain-check', [], false), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!};
    const publisherDomainsUrl = {!! json_encode(staff_route('sites.publisher-domains', [], false), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!};
    const lookupMetricsUrl = {!! json_encode(staff_route('sites.lookup-metrics', [], false), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!};
    const csrfToken = @json(csrf_token());

    function publisherIsUnverified() {
        if (!publisherSelect) return false;
        const selected = publisherSelect.options[publisherSelect.selectedIndex];
        return !!(selected && selected.value && selected.getAttribute('data-verified') === '0');
    }

    function refreshUnverifiedPublisherWarn() {
        const unverified = publisherIsUnverified();
        if (unverifiedWarn) unverifiedWarn.classList.toggle('d-none', !unverified);
        if (assignSubmitBtn) assignSubmitBtn.disabled = unverified;
    }

    function appendText(parent, text) {
        parent.appendChild(document.createTextNode(text));
    }

    function refreshPublisherDomains() {
        if (!publisherDossier || !publisherSelect) return;
        const id = publisherSelect.value;
        if (!id) {
            publisherDossier.classList.add('d-none');
            if (publisherDossierMeta) publisherDossierMeta.replaceChildren();
            if (publisherDomains) publisherDomains.replaceChildren();
            return;
        }
        fetch(publisherDomainsUrl + '?publisher=' + encodeURIComponent(id), { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                const pub = data.publisher && typeof data.publisher === 'object' ? data.publisher : {};
                if (publisherDossierMeta) {
                    publisherDossierMeta.replaceChildren();
                    const line = document.createElement('div');
                    const name = String(pub.name || '').trim();
                    const email = String(pub.email || '').trim();
                    line.textContent = [name, email].filter(Boolean).join(' · ');
                    publisherDossierMeta.appendChild(line);
                    const links = document.createElement('div');
                    if (data.sites_url) {
                        const a = document.createElement('a');
                        a.href = data.sites_url;
                        a.textContent = 'Open Sites for this publisher';
                        links.appendChild(a);
                    }
                    if (data.user_url) {
                        if (links.childNodes.length) appendText(links, ' · ');
                        const a = document.createElement('a');
                        a.href = data.user_url;
                        a.textContent = 'Open user';
                        links.appendChild(a);
                    }
                    if (links.childNodes.length) publisherDossierMeta.appendChild(links);
                }
                if (publisherDomains) {
                    publisherDomains.replaceChildren();
                    const domains = Array.isArray(data.domains) ? data.domains : [];
                    const total = Number(data.total || domains.length);
                    if (!domains.length) {
                        publisherDomains.textContent = 'This publisher has no sites yet.';
                    } else {
                        appendText(publisherDomains, 'Already on this account: ');
                        domains.forEach(function (row, i) {
                            if (i) appendText(publisherDomains, ', ');
                            const domain = typeof row === 'string' ? row : String(row.domain || '');
                            const listing = typeof row === 'object' ? String(row.listing_url || '') : '';
                            if (listing && domain) {
                                const a = document.createElement('a');
                                a.href = listing;
                                a.textContent = domain;
                                publisherDomains.appendChild(a);
                            } else {
                                appendText(publisherDomains, domain);
                            }
                        });
                        if (total > domains.length) {
                            appendText(publisherDomains, ' (+' + (total - domains.length) + ' more)');
                        }
                    }
                }
                publisherDossier.classList.remove('d-none');
            })
            .catch(function () {
                publisherDossier.classList.add('d-none');
            });
    }

    if (publisherSelect) {
        publisherSelect.addEventListener('change', function () {
            refreshUnverifiedPublisherWarn();
            refreshPublisherDomains();
        });
        refreshUnverifiedPublisherWarn();
        refreshPublisherDomains();
    }

    const siteUrlInput = document.getElementById('site_url');
    const siteUrlStatus = document.getElementById('siteUrlStatus');
    const siteUrlCanonical = document.getElementById('siteUrlCanonical');
    const siteNameInput = document.getElementById('site_name');
    const exampleUrlInput = document.getElementById('example_url');
    const metricsStatus = document.getElementById('metricsStatus');
    let domainTimer = null;
    let domainCheckSeq = 0;

    function siteOrigin(raw) {
        const value = String(raw || '').trim();
        if (!value) return '';
        try {
            const parsed = new URL(value.includes('://') ? value : 'https://' + value);
            return parsed.hostname ? parsed.origin : '';
        } catch (e) {
            return '';
        }
    }

    function hostAsName(raw) {
        const origin = siteOrigin(raw);
        if (!origin) return '';
        try {
            const host = new URL(origin).hostname.replace(/^www\./i, '');
            const bit = host.split('.')[0] || '';
            if (!bit) return '';
            return bit.charAt(0).toUpperCase() + bit.slice(1);
        } catch (e) {
            return '';
        }
    }

    function refreshCanonical() {
        if (!siteUrlCanonical) return;
        const origin = siteUrlInput ? siteOrigin(siteUrlInput.value) : '';
        siteUrlCanonical.textContent = origin ? ('Origin: ' + origin) : '';
    }

    function fillNameFromUrl(force) {
        if (!siteNameInput || !siteUrlInput) return;
        if (!force && String(siteNameInput.value || '').trim() !== '') return;
        const name = hostAsName(siteUrlInput.value);
        if (name) siteNameInput.value = name;
    }

    function fillExampleFromUrl(force) {
        if (!exampleUrlInput || !siteUrlInput) return;
        if (!force && String(exampleUrlInput.value || '').trim() !== '') return;
        const origin = siteOrigin(siteUrlInput.value);
        if (origin) exampleUrlInput.value = origin + '/sample-post';
    }

    function checkDomain() {
        if (!siteUrlInput || !siteUrlStatus) return;
        const value = String(siteUrlInput.value || '').trim();
        const seq = ++domainCheckSeq;
        refreshCanonical();
        if (value.length < 4) {
            siteUrlStatus.replaceChildren();
            siteUrlStatus.classList.remove('text-danger');
            return;
        }
        fetch(domainCheckUrl + '?site_url=' + encodeURIComponent(value), { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (seq !== domainCheckSeq) return;
                siteUrlStatus.replaceChildren();
                if (data.message) appendText(siteUrlStatus, data.message);
                if (data.available === false && data.listing_url) {
                    appendText(siteUrlStatus, ' ');
                    const a = document.createElement('a');
                    a.href = data.listing_url;
                    a.textContent = 'Open listing';
                    siteUrlStatus.appendChild(a);
                }
                siteUrlStatus.classList.toggle('text-danger', data.available === false);
            })
            .catch(function () {
                if (seq !== domainCheckSeq) return;
                siteUrlStatus.replaceChildren();
                siteUrlStatus.classList.remove('text-danger');
            });
    }
    if (siteUrlInput) {
        siteUrlInput.addEventListener('input', function () {
            clearTimeout(domainTimer);
            domainTimer = setTimeout(checkDomain, 400);
        });
        siteUrlInput.addEventListener('blur', function () {
            checkDomain();
            fillNameFromUrl(false);
            fillExampleFromUrl(false);
        });
        refreshCanonical();
        if (String(siteUrlInput.value || '').trim()) checkDomain();
    }
    const fillNameBtn = document.getElementById('fillNameFromUrlBtn');
    if (fillNameBtn) fillNameBtn.addEventListener('click', function () { fillNameFromUrl(true); });
    const fillExampleBtn = document.getElementById('fillExampleFromUrlBtn');
    if (fillExampleBtn) fillExampleBtn.addEventListener('click', function () { fillExampleFromUrl(true); });

    const lookupBtn = document.getElementById('lookupMetricsBtn');
    const lookupIdleLabel = lookupBtn ? String(lookupBtn.textContent || 'Look up metrics').trim() : 'Look up metrics';
    if (lookupBtn && siteUrlInput) {
        lookupBtn.addEventListener('click', function () {
            lookupBtn.disabled = true;
            lookupBtn.classList.add('is-busy');
            lookupBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Looking up…';
            fetch(lookupMetricsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ site_url: siteUrlInput.value }),
            }).then(async function (res) {
                const data = await res.json().catch(function () { return {}; });
                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Metrics have to be typed.');
                }
                if (data.da != null) document.getElementById('da').value = data.da;
                if (data.dr != null) document.getElementById('dr').value = data.dr;
                if (data.traffic != null) document.getElementById('traffic').value = data.traffic;
                if (typeof refreshQualityBar === 'function') refreshQualityBar();
                if (metricsStatus) {
                    metricsStatus.textContent = data.message || 'Metrics filled. You can still edit them.';
                    metricsStatus.classList.remove('text-danger');
                }
            }).catch(function (err) {
                if (metricsStatus) {
                    metricsStatus.textContent = err.message || 'Metrics have to be typed.';
                    metricsStatus.classList.add('text-danger');
                }
            }).finally(function () {
                lookupBtn.disabled = false;
                lookupBtn.classList.remove('is-busy');
                lookupBtn.textContent = lookupIdleLabel;
            });
        });
    }

    const imagePreview = document.getElementById('siteImagePreview');
    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', function () {
            const file = imageInput.files && imageInput.files[0];
            if (!file) {
                imagePreview.classList.add('d-none');
                imagePreview.removeAttribute('src');
                return;
            }
            const reader = new FileReader();
            reader.onload = function () {
                imagePreview.src = reader.result;
                imagePreview.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        });
    }

    const form = document.getElementById('staffAssignSiteForm');
    const hidden = document.getElementById('selectedCategories');
    const writtenRequest = document.getElementById('written_request');
    let assignConfirmed = false;
    if (form) {
        form.addEventListener('submit', function (e) {
            if (langEl) {
                langEl.disabled = false;
            }
            syncLanguageHidden();
            if (publisherIsUnverified()) {
                e.preventDefault();
                refreshUnverifiedPublisherWarn();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Choose a publisher who has verified their email' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Choose a publisher who has verified their email', timer: 2800, showConfirmButton: false });
                }
                return;
            }
            if (!languageValue()) {
                e.preventDefault();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Select a language' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Select a language', timer: 2200, showConfirmButton: false });
                }
                return;
            }
            if (hidden && !String(hidden.value || '').trim()) {
                e.preventDefault();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Select at least one niche' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Select at least one niche', timer: 2200, showConfirmButton: false });
                }
                return;
            }
            if (imageInput && imageInput.files && imageInput.files[0]) {
                const maxKb = parseInt(imageInput.getAttribute('data-max-kb') || '10240', 10);
                const maxBytes = maxKb * 1024;
                if (imageInput.files[0].size > maxBytes) {
                    e.preventDefault();
                    const title = (window.SiteImageUpload && window.SiteImageUpload.sizeError)
                        ? window.SiteImageUpload.sizeError(maxKb)
                        : ('Site image must be under ' + Math.floor(maxKb / 1024) + ' MB');
                    if (window.slbAlert) {
                        window.slbAlert({ icon: 'warning', title: title });
                    } else if (window.Swal) {
                        Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
                    }
                    return;
                }
            }
            if (writtenRequest && !writtenRequest.checked) {
                e.preventDefault();
                if (window.slbAlert) {
                    window.slbAlert({ icon: 'warning', title: 'Confirm you have a written request from this publisher’s account email' });
                } else if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Confirm you have a written request from this publisher’s account email', timer: 2800, showConfirmButton: false });
                }
                return;
            }
            if (!assignConfirmed && typeof window.slbConfirm === 'function') {
                e.preventDefault();
                window.slbConfirm({
                    title: 'Add site & notify publisher?',
                    text: 'This emails and bells the publisher. They must Accept the invite in My Sites.',
                    confirmText: 'Add site & notify',
                }).then(function (ok) {
                    if (!ok) return;
                    assignConfirmed = true;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        HTMLFormElement.prototype.submit.call(form);
                    }
                });
            }
        });
    }
})();
</script>
@endsection
